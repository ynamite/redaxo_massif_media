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
     * Drain the queue and return the rendered <link> tags.
     * Called by the OUTPUT_FILTER hook in boot.php.
     */
    public static function drain(): string
    {
        if (self::$queue === []) {
            return '';
        }

        $sizes = Config::defaultSizes();
        $formats = Config::formats();

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
            $useWidths = $srcsetBuilder->build($image->intrinsicWidth, $entry['widths']);
            if ($useWidths === []) {
                continue;
            }

            // Preload only the most-preferred (first) format — typically AVIF.
            $primaryFormat = strtolower((string) ($useFormats[0] ?? 'jpg'));
            $primaryQuality = $entry['quality'][$primaryFormat] ?? null;

            $srcsetParts = [];
            foreach ($useWidths as $w) {
                $url = $urlBuilder->build($image, $w, $primaryFormat, $primaryQuality);
                $srcsetParts[] = $url . ' ' . $w . 'w';
            }
            $imageSrcset = implode(', ', $srcsetParts);

            $mime = $primaryFormat === 'jpg' ? 'image/jpeg' : 'image/' . $primaryFormat;
            $links[] = sprintf(
                '<link rel="preload" as="image" type="%s" imagesrcset="%s" imagesizes="%s">',
                htmlspecialchars($mime, ENT_QUOTES),
                htmlspecialchars($imageSrcset, ENT_QUOTES),
                htmlspecialchars($useSizes, ENT_QUOTES),
            );
        }

        self::$queue = [];
        return implode('', $links);
    }

    public static function reset(): void
    {
        self::$queue = [];
    }
}
