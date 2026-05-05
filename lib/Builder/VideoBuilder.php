<?php

declare(strict_types=1);

namespace Ynamite\Media\Builder;

use rex;
use rex_logger;
use rex_media;
use rex_path;
use rex_url;
use RuntimeException;
use Throwable;
use Ynamite\Media\Config;
use Ynamite\Media\Enum\Loading;
use Ynamite\Media\Pipeline\Preloader;

final class VideoBuilder
{
    private string|rex_media $src;
    private ?string $poster = null;
    private ?int $width = null;
    private ?int $height = null;
    private ?string $alt = null;
    private bool $autoplay = false;
    private bool $muted = false;
    private bool $loop = false;
    private bool $controls = true;
    private bool $playsinline = true;
    private string $preload = 'metadata';
    private bool $linkPreload = false;
    private Loading $loading = Loading::LAZY;
    private ?string $class = null;

    public function __construct(string|rex_media $src)
    {
        $this->src = $src;
    }

    public function poster(string $poster): self
    {
        $this->poster = $poster;
        return $this;
    }

    public function width(int $width): self
    {
        $this->width = $width;
        return $this;
    }

    public function height(int $height): self
    {
        $this->height = $height;
        return $this;
    }

    public function alt(string $alt): self
    {
        $this->alt = $alt;
        return $this;
    }

    public function autoplay(bool $on = true): self
    {
        $this->autoplay = $on;
        return $this;
    }

    public function muted(bool $on = true): self
    {
        $this->muted = $on;
        return $this;
    }

    public function loop(bool $on = true): self
    {
        $this->loop = $on;
        return $this;
    }

    public function controls(bool $on = true): self
    {
        $this->controls = $on;
        return $this;
    }

    public function playsinline(bool $on = true): self
    {
        $this->playsinline = $on;
        return $this;
    }

    /**
     * Accepts: 'none' | 'metadata' | 'auto'. Invalid values fall back to 'metadata'.
     */
    public function preload(string $preload): self
    {
        $this->preload = in_array($preload, ['none', 'metadata', 'auto'], true)
            ? $preload
            : 'metadata';
        return $this;
    }

    /**
     * Inject `<link rel="preload">` entries into the page `<head>` for this
     * video and (when present and preloadable) its poster.
     *
     * Orthogonal to `preload()` — that's the HTML `<video preload>` attribute,
     * which controls whether the browser prefetches metadata / data after the
     * `<video>` element is parsed. `linkPreload` runs the preload during the
     * head-parse phase, before the body. Set both to `true` / `'auto'` for
     * above-the-fold hero videos where the LCP is the video.
     *
     * Poster preload semantics are conservative: only posters that are URLs
     * (`://`), absolute paths, or data URIs are preloaded. Bare-filename
     * posters (which the browser would resolve relative to the page URL —
     * a known asymmetry tracked as a v2 candidate) are skipped silently to
     * avoid emitting a preload URL that doesn't match what `<video poster>`
     * actually fetches. The recipe for a responsive Mediapool poster URL is
     * `Image::url(...)` (or `REX_PIC[as=url]`).
     */
    public function linkPreload(bool $on = true): self
    {
        $this->linkPreload = $on;
        return $this;
    }

    public function loading(Loading|string $loading): self
    {
        $this->loading = is_string($loading) ? Loading::from($loading) : $loading;
        return $this;
    }

    public function class(string $class): self
    {
        $this->class = $class;
        return $this;
    }

    public function render(): string
    {
        $filename = $this->src instanceof rex_media ? $this->src->getFileName() : $this->src;
        if ($filename === '') {
            return self::missingSrcMarker('');
        }

        $media = $this->src instanceof rex_media ? $this->src : rex_media::get($filename);
        $absPath = rex_path::media($filename);
        if (!is_readable($absPath)) {
            rex_logger::logException(
                new RuntimeException('massif_media: video src not readable: ' . $filename),
            );
            return self::missingSrcMarker($filename);
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mtime = (int) (filemtime($absPath) ?: 0);
        $url = $this->buildUrl($filename, $mtime);

        $validatedPoster = ($this->poster !== null && $this->poster !== '')
            ? self::validatePoster($this->poster)
            : null;

        $attrs = [];
        if ($this->class !== null && $this->class !== '') {
            $attrs['class'] = $this->class;
        }
        if ($this->width !== null) {
            $attrs['width'] = (string) $this->width;
        }
        if ($this->height !== null) {
            $attrs['height'] = (string) $this->height;
        }
        if ($validatedPoster !== null) {
            $attrs['poster'] = $validatedPoster;
        }
        if ($this->alt !== null && $this->alt !== '') {
            $attrs['aria-label'] = $this->alt;
        }
        $attrs['preload'] = $this->preload;

        if ($this->linkPreload) {
            Preloader::queueLink($url, 'video', self::videoMimeType($ext));
            if ($validatedPoster !== null && self::isPosterPreloadable($validatedPoster)) {
                Preloader::queueLink($validatedPoster, 'image');
            }
        }

        $bools = [];
        if ($this->controls) {
            $bools[] = 'controls';
        }
        if ($this->autoplay) {
            $bools[] = 'autoplay';
        }
        if ($this->muted) {
            $bools[] = 'muted';
        }
        if ($this->loop) {
            $bools[] = 'loop';
        }
        if ($this->playsinline) {
            $bools[] = 'playsinline';
        }

        $tag = '<video';
        foreach ($attrs as $name => $value) {
            $tag .= ' ' . $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        foreach ($bools as $b) {
            $tag .= ' ' . $b;
        }
        $tag .= '>';
        $tag .= '<source src="' . htmlspecialchars($url, ENT_QUOTES) . '" type="video/' . htmlspecialchars($ext, ENT_QUOTES) . '">';
        $tag .= '</video>';

        return $tag;
    }

    public function __toString(): string
    {
        try {
            return $this->render();
        } catch (Throwable $e) {
            rex_logger::logException($e);
            return '';
        }
    }

    /**
     * Drop poster references to local mediapool files that don't exist.
     *
     * Browsers handle a missing `<video poster>` URL poorly: WebKit/Blink hold
     * the broken-image's 0×0 box as the video's intrinsic size until video
     * metadata loads, which collapses the layout when no `width`/`height`
     * attrs are set. The HTML5 spec says a failed poster should fall back to
     * "no poster", but engines diverge. The robust fix is to never emit a
     * poster URL that we know is broken.
     *
     * Validation is conservative: only bare filenames (treated as mediapool
     * references) are checked for existence. URLs (containing `://`),
     * absolute paths (`/...` or `//...`), and data URIs (`data:...`) pass
     * through unchanged because we can't cheaply verify them. Returns null
     * when the bare filename can be definitively shown not to exist on disk
     * AND has no `rex_media` record — caller drops the attribute.
     *
     * Out of scope (a v2 candidate): normalising bare-filename posters to
     * full mediapool URLs the way `buildUrl()` does for `src`. That would
     * be a behaviour change for users who currently pass same-folder
     * relative paths and rely on browser-relative URL resolution.
     */
    /**
     * Map a video filename extension to its preload `<link type>` MIME.
     *
     * Returning null (unknown extension) tells the caller to omit `type=` —
     * browsers accept the preload without it; emitting a wrong MIME would
     * cause the preload to be ignored.
     *
     *   mp4 / m4v → video/mp4 (m4v is technically MPEG-4 Visual but every
     *               container that uses .m4v extension carries an MP4 stream)
     *   webm      → video/webm
     *   ogv / ogg → video/ogg
     *   mov       → video/quicktime  (NOT video/mov — that's not a registered MIME
     *               and Safari's preload scheduler ignores it)
     */
    private static function videoMimeType(string $ext): ?string
    {
        return match (strtolower($ext)) {
            'mp4', 'm4v' => 'video/mp4',
            'webm' => 'video/webm',
            'ogv', 'ogg' => 'video/ogg',
            'mov' => 'video/quicktime',
            default => null,
        };
    }

    /**
     * Only preload posters whose URL the browser will actually fetch the way
     * we emit it. Bare-filename posters (passed validatePoster() because the
     * mediapool entry exists) get rendered as `<video poster="hero.jpg">`,
     * which the browser resolves relative to the page URL — almost never
     * what the user wants, and emitting a `<link rel="preload">` for the
     * Mediapool URL would create an inconsistent fetch. The user should
     * route through `Image::url()` / `REX_PIC[as=url]` for the responsive
     * recipe.
     */
    private static function isPosterPreloadable(string $poster): bool
    {
        return str_contains($poster, '://')
            || str_starts_with($poster, '/')
            || str_starts_with($poster, 'data:');
    }

    private static function validatePoster(string $poster): ?string
    {
        if (
            str_contains($poster, '://')
            || str_starts_with($poster, '/')
            || str_starts_with($poster, 'data:')
        ) {
            return $poster;
        }
        $absPath = rex_path::media($poster);
        if (is_readable($absPath)) {
            return $poster;
        }
        if (rex_media::get($poster) !== null) {
            return $poster;
        }
        rex_logger::logException(
            new RuntimeException('massif_media: poster not found: ' . $poster),
        );
        return null;
    }

    private function buildUrl(string $filename, int $mtime): string
    {
        if (Config::cdnEnabled()) {
            $base = Config::cdnBase();
            return $base . '/' . ltrim($filename, '/') . '?v=' . $mtime;
        }
        return rex_url::base() . 'media/' . $filename . '?v=' . $mtime;
    }

    /**
     * In rex::isDebug() returns an HTML comment naming the missing src so
     * editors see the typo in the page source. Empty string in production.
     */
    private static function missingSrcMarker(string $filename): string
    {
        if (!rex::isDebug()) {
            return '';
        }
        return sprintf(
            '<!-- massif_media: src not found "%s" -->',
            htmlspecialchars($filename, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }
}
