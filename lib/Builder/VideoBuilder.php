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
        if ($this->poster !== null && $this->poster !== '') {
            $attrs['poster'] = $this->poster;
        }
        if ($this->alt !== null && $this->alt !== '') {
            $attrs['aria-label'] = $this->alt;
        }
        $attrs['preload'] = $this->preload;

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
