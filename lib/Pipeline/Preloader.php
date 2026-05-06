<?php

declare(strict_types=1);

namespace Ynamite\Media\Pipeline;

use Ynamite\Media\Config;
use Ynamite\Media\Enum\Fit;

/**
 * Collects <link rel="preload"> entries during rendering.
 * The OUTPUT_FILTER (registered in boot.php) consumes the queue and injects
 * <link> tags into the page <head> before output is sent.
 */
final class Preloader
{
    /** @var array<int, array{image: ResolvedImage, width: ?int, height: ?int, ratio: ?float, sizes: ?string, widths: ?array, formats: ?array, quality: ?array, fit: ?Fit, filterParams: array}> */
    private static array $queue = [];

    /** @var array<int, array{href: string, as: string, type: ?string}> */
    private static array $rawLinks = [];

    public static function queue(
        ResolvedImage $image,
        ?int $width = null,
        ?int $height = null,
        ?float $ratio = null,
        ?string $sizes = null,
        ?array $widths = null,
        ?array $formats = null,
        ?array $quality = null,
        ?Fit $fit = null,
        array $filterParams = [],
    ): void {
        self::$queue[] = compact(
            'image', 'width', 'height', 'ratio', 'sizes',
            'widths', 'formats', 'quality', 'fit', 'filterParams',
        );
    }

    /**
     * Queue a single-URL preload (no responsive srcset).
     *
     * Use for `<video>` src (`as="video"`) and `<video poster>` (`as="image"`)
     * — both are single-URL by spec, so the responsive `imagesrcset` shape
     * `queue()` produces would be wasted bytes. For responsive `<picture>`
     * preloads, use `queue()` instead.
     */
    public static function queueLink(string $href, string $as, ?string $type = null): void
    {
        self::$rawLinks[] = compact('href', 'as', 'type');
    }

    /**
     * Drain the queue and return the rendered <link> tags.
     * Called by the OUTPUT_FILTER hook in boot.php.
     */
    public static function drain(): string
    {
        if (self::$queue === [] && self::$rawLinks === []) {
            return '';
        }

        $sizes = Config::defaultSizes();
        $formats = Config::renderableFormats();

        $links = [];
        $srcsetBuilder = new SrcsetBuilder();
        $urlBuilder = new UrlBuilder();

        foreach (self::$queue as $entry) {
            /** @var ResolvedImage $image */
            $image = $entry['image'];
            if ($image->isPassthrough()) {
                continue;
            }
            $useFormats = $entry['formats'] ?? $formats;
            $useSizes = $entry['sizes'] ?? $sizes;

            $ctx = RenderContext::build(
                image: $image,
                width: $entry['width'],
                height: $entry['height'],
                ratio: $entry['ratio'],
                fit: $entry['fit'] ?? null,
                widthsOverride: $entry['widths'],
                srcsetBuilder: $srcsetBuilder,
            );
            if ($ctx->widths === []) {
                continue;
            }

            // Preload only the most-preferred (first) format — typically AVIF.
            $primaryFormat = strtolower((string) ($useFormats[0] ?? 'jpg'));
            $primaryQuality = $entry['quality'][$primaryFormat] ?? null;
            $filterParams = $entry['filterParams'] ?? [];

            $imageSrcset = $ctx->buildSrcset($urlBuilder, $image, $primaryFormat, $primaryQuality, $filterParams);

            $mime = $primaryFormat === 'jpg' ? 'image/jpeg' : 'image/' . $primaryFormat;
            // fetchpriority="high" satisfies Lighthouse's "LCP request discovery"
            // audit. Without it the preload fetch sits at default image
            // priority and competes with below-fold lazy images on slow
            // connections — the warning specifically calls out that preloading
            // alone isn't enough; the LCP fetch needs explicit high priority.
            // Preloading is opt-in and semantically means "this is the
            // above-the-fold hero", so always-on high priority is correct.
            $links[] = sprintf(
                '<link rel="preload" as="image" type="%s" imagesrcset="%s" imagesizes="%s" fetchpriority="high">',
                htmlspecialchars($mime, ENT_QUOTES),
                htmlspecialchars($imageSrcset, ENT_QUOTES),
                htmlspecialchars($useSizes, ENT_QUOTES),
            );
        }

        self::$queue = [];

        foreach (self::$rawLinks as $link) {
            $attrs = sprintf(
                'as="%s" href="%s"',
                htmlspecialchars($link['as'], ENT_QUOTES),
                htmlspecialchars($link['href'], ENT_QUOTES),
            );
            if ($link['type'] !== null && $link['type'] !== '') {
                $attrs .= sprintf(' type="%s"', htmlspecialchars($link['type'], ENT_QUOTES));
            }
            $links[] = '<link rel="preload" ' . $attrs . ' fetchpriority="high">';
        }
        self::$rawLinks = [];

        return implode('', $links);
    }

    public static function reset(): void
    {
        self::$queue = [];
        self::$rawLinks = [];
    }
}
