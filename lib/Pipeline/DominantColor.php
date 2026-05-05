<?php

declare(strict_types=1);

namespace Ynamite\Media\Pipeline;

use Imagick;
use rex_file;
use rex_logger;
use rex_path;
use Throwable;
use Ynamite\Media\Config;

/**
 * Computes (and caches) a single representative `#rrggbb` for a raster source,
 * suitable for `background-color` so the slot has a non-white block before any
 * image data has loaded. Cheaper than LQIP — 7 bytes vs ~600 bytes — and
 * complementary: when both are enabled the colour paints first, the LQIP
 * `background-image` overlays it as soon as it decodes.
 *
 * Generation uses Imagick::quantizeImage(1) which collapses every pixel to one
 * representative colour. ~5–20ms on typical photos. If Imagick is missing, or
 * any step throws, we log + return '' (caller treats empty as "skip").
 *
 * Cache layout mirrors `Placeholder`: `cache/_color/<2-char-prefix>/<hash>.txt`,
 * keyed on `xxh64(sourcePath:mtime:CACHE_VERSION)` so stored files self-
 * invalidate when the encoding contract bumps without needing a manual clear.
 */
final class DominantColor
{
    /**
     * Bumped whenever the colour-extraction contract changes (algorithm,
     * colour space, output format) so existing _color/*.txt files self-invalidate.
     *   v1: Imagick quantize-to-1, sRGB, '#rrggbb' lowercase output
     */
    private const CACHE_VERSION = 'v1';

    public function generate(ResolvedImage $image): string
    {
        if (!Config::colorEnabled() || $image->isPassthrough()) {
            return '';
        }

        $cachePath = $this->cacheFile($image);
        if (is_file($cachePath)) {
            $cached = (string) file_get_contents($cachePath);
            if ($cached !== '') {
                return $cached;
            }
        }

        if (!extension_loaded('imagick') || !is_readable($image->absolutePath)) {
            return '';
        }

        try {
            $im = new Imagick();
            $im->readImage($image->absolutePath);
            // Tiny working copy so quantize is fast on 6000×4000 sources.
            $im->scaleImage(50, 0);
            // COLORSPACE_SRGB (not COLORSPACE_RGB!) — Imagick's "RGB" is
            // linear-light space; quantizing there produces gamma-incorrect
            // averages that come back as much darker than the perceived
            // dominant colour. SRGB is what users see and what we want here.
            $im->quantizeImage(1, Imagick::COLORSPACE_SRGB, 0, false, false);
            $hist = $im->getImageHistogram();
            $im->clear();

            if ($hist === []) {
                return '';
            }
            // getColor(2) returns 8-bit channel values regardless of Imagick's
            // compile-time quantum depth. The default getColor(0) returns
            // quantum-scaled values which would be 0-65535 on a Q16 build.
            $rgb = $hist[0]->getColor(2);
            $hex = sprintf('#%02x%02x%02x', $rgb['r'], $rgb['g'], $rgb['b']);
        } catch (Throwable $e) {
            rex_logger::logException($e);
            return '';
        }

        rex_file::put($cachePath, $hex);
        return $hex;
    }

    private function cacheFile(ResolvedImage $image): string
    {
        return self::cachePathFor($image->sourcePath, $image->mtime);
    }

    public static function cachePathFor(string $filename, int $mtime): string
    {
        $hash = hash('xxh64', $filename . ':' . $mtime . ':' . self::CACHE_VERSION);
        return rex_path::addonAssets(
            Config::ADDON,
            'cache/_color/' . substr($hash, 0, 2) . '/' . $hash . '.txt',
        );
    }
}
