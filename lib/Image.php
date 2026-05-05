<?php

declare(strict_types=1);

namespace Ynamite\Media;

use rex_media;
use Ynamite\Media\Builder\ImageBuilder;
use Ynamite\Media\Enum\Decoding;
use Ynamite\Media\Enum\FetchPriority;
use Ynamite\Media\Enum\Fit;
use Ynamite\Media\Enum\Loading;

class Image
{
    /**
     * The 80% case: render a <picture> with named arguments.
     *
     * For complex cases (focal override, preload, custom widths/quality),
     * use Image::for($src)->...->render() instead.
     */
    public static function picture(
        string|rex_media $src,
        ?string $alt = null,
        ?int $width = null,
        ?int $height = null,
        ?float $ratio = null,
        ?string $sizes = null,
        Loading|string $loading = Loading::LAZY,
        Decoding|string $decoding = Decoding::ASYNC,
        FetchPriority|string $fetchPriority = FetchPriority::AUTO,
        ?string $focal = null,
        bool $preload = false,
        ?string $class = null,
        Fit|string|null $fit = null,
        array $filters = [],
        array $art = [],
    ): string {
        $b = self::for($src);
        if ($alt !== null) {
            $b->alt($alt);
        }
        if ($width !== null) {
            $b->width($width);
        }
        if ($height !== null) {
            $b->height($height);
        }
        if ($ratio !== null) {
            $b->ratio($ratio);
        }
        if ($sizes !== null) {
            $b->sizes($sizes);
        }
        $b->loading($loading)->decoding($decoding)->fetchPriority($fetchPriority);
        if ($focal !== null) {
            $b->focal($focal);
        }
        if ($preload) {
            $b->preload();
        }
        if ($class !== null) {
            $b->class($class);
        }
        if ($fit !== null) {
            $b->fit($fit);
        }
        if ($filters !== []) {
            $b->filters($filters);
        }
        if ($art !== []) {
            $b->art($art);
        }
        return $b->render();
    }

    /**
     * Start a fluent builder. For complex cases or chained composition.
     */
    public static function for(string|rex_media $src): ImageBuilder
    {
        return new ImageBuilder($src);
    }

    /**
     * Return a single signed URL for one variant — no `<picture>` markup.
     *
     * The escape hatch for cases the responsive `<picture>` API can't cover:
     * `<video poster>` (HTML5 has no `srcset` for posters), Open Graph /
     * Twitter card images, CSS `background-image`, JS-driven canvas. All
     * filters / fit / focal are honoured; the URL goes through the same
     * Glide cache as `picture()`, so a `url(width: 1280, format: 'webp')`
     * call shares its on-disk cache file with the matching `<picture>`
     * variant at the same width / format / quality.
     *
     * Default format: first of `Config::formats()` (or `'webp'` if empty).
     * Default width: median of the `effectiveMaxWidth`-capped width pool —
     * the same choice `PictureRenderer` makes for the fallback `<img src>`.
     *
     * Passthrough sources (SVG / GIF) return the raw mediapool URL — width /
     * filters silently ignored. Animated GIFs return the static GIF URL, not
     * the animated WebP wrap (the `<video poster>` + animated-WebP combo is
     * undefined per HTML5).
     */
    public static function url(
        string|rex_media $src,
        ?int $width = null,
        ?int $height = null,
        ?float $ratio = null,
        ?string $format = null,
        ?int $quality = null,
        Fit|string|null $fit = null,
        ?string $focal = null,
        array $filters = [],
    ): string {
        $b = self::for($src);
        if ($width !== null) {
            $b->width($width);
        }
        if ($height !== null) {
            $b->height($height);
        }
        if ($ratio !== null) {
            $b->ratio($ratio);
        }
        if ($fit !== null) {
            $b->fit($fit);
        }
        if ($focal !== null) {
            $b->focal($focal);
        }
        if ($filters !== []) {
            $b->filters($filters);
        }
        return $b->url($format, $quality);
    }
}
