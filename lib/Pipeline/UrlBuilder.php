<?php

declare(strict_types=1);

namespace Ynamite\Media\Pipeline;

use rex_url;
use Ynamite\Media\Config;
use Ynamite\Media\Glide\Server;
use Ynamite\Media\Glide\Signature;

final class UrlBuilder
{
    /**
     * Build a URL for a single variant of a resolved image.
     *
     * - When CDN is enabled: emits a CDN URL based on cdn_base + cdn_url_template.
     * - Otherwise: emits a signed local URL pointing at the addon's cache, with a
     *   ?v={mtime} parameter for browser / CDN cache busting on source changes.
     *
     * Glide params (w/h/q/fm/fit) are encoded into the cache path itself, not
     * appended as URL query parameters. The URL query string only carries
     * ?s={hmac} and &v={mtime}.
     *
     * `$height` and `$fitToken` are non-null only when the caller wants a crop.
     * `$fitToken` follows our internal vocabulary: `cover-{X}-{Y}` (focal-aware),
     * `contain`, or `stretch`. The Endpoint translates `cover-X-Y` to Glide's
     * `crop-X-Y` at the boundary.
     */
    public function build(
        ResolvedImage $image,
        int $width,
        string $format,
        ?int $quality = null,
        ?int $height = null,
        ?string $fitToken = null,
        array $filterParams = [],
    ): string {
        $quality ??= Config::quality($format);

        if (Config::cdnEnabled()) {
            return $this->buildCdnUrl($image, $width, $format, $quality, $height, $fitToken);
        }

        $cachePath = Server::cachePath($image->sourcePath, [
            'fm' => $format,
            'w' => $width,
            'q' => $quality,
            'h' => $height,
            'fit' => $fitToken,
        ]);
        $signature = Signature::sign($cachePath);

        $url = rex_url::addonAssets(Config::ADDON, 'cache/' . $cachePath);
        $url .= '?s=' . $signature;
        if ($image->mtime > 0) {
            $url .= '&v=' . $image->mtime;
        }
        return $url;
    }

    /**
     * Build a CDN URL using the configured base and template.
     *
     * Template tokens: {w}, {h}, {q}, {fm}, {fit}, {src}.
     * - {h} expands to the height (or empty string when not cropping).
     * - {fit} expands to the fit token (or empty string when not cropping).
     * Existing templates without {h}/{fit} keep emitting the same URLs as today.
     * Example template: "tr:w-{w},q-{q},f-{fm}/{src}" (ImageKit-style).
     */
    private function buildCdnUrl(
        ResolvedImage $image,
        int $width,
        string $format,
        int $quality,
        ?int $height,
        ?string $fitToken,
    ): string {
        $template = Config::cdnUrlTemplate();
        if ($template === '') {
            $template = '{src}?w={w}&q={q}&fm={fm}';
        }

        $expanded = strtr($template, [
            '{w}' => (string) $width,
            '{q}' => (string) $quality,
            '{fm}' => $format,
            '{src}' => $image->sourcePath,
            '{h}' => $height !== null ? (string) $height : '',
            '{fit}' => $fitToken ?? '',
        ]);

        $base = Config::cdnBase();
        return $base . '/' . ltrim($expanded, '/');
    }
}
