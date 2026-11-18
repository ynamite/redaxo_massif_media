<?php

declare(strict_types=1);

namespace Ynamite\Media\Pipeline;

use Imagick;
use rex_file;
use rex_logger;
use rex_path;
use Throwable;
use Ynamite\Media\Config;
use Ynamite\Media\Source\SourceInterface;

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
 * keyed on `xxh64(source.key():source.cacheBust():CACHE_VERSION)` so stored
 * files self-invalidate when the encoding contract bumps without needing a
 * manual clear.
 *
 * Cache misses run through {@see RenderBudget}: past the per-request budget
 * a miss returns '' and a later request generates it.
 */
final class DominantColor
{
    /**
     * Bumped whenever the colour-extraction contract changes (algorithm,
     * colour space, output format) so existing _color/*.txt files self-invalidate.
     *   v1: Imagick quantize-to-1, sRGB, '#rrggbb' lowercase output
     */
    private const CACHE_VERSION = 'v1';

    /**
     * Sidecar content for images with transparent pixels. A background colour
     * stays visible behind them after load, so they get none; the sidecar
     * remembers that so the file is not decoded again on every render.
     */
    private const NO_COLOR = 'transparent';

    private const OPAQUE_ENOUGH = 0.95;

    public function generate(ResolvedImage $image): string
    {
        if (!Config::colorEnabled() || $image->sourceFormat === 'svg') {
            return '';
        }

        $cachePath = self::cachePathFor($image->source);
        if (is_file($cachePath)) {
            $cached = (string) file_get_contents($cachePath);
            if ($cached !== '') {
                return $cached === self::NO_COLOR ? '' : $cached;
            }
        }

        return RenderBudget::run(fn (): string => $this->compute($image, $cachePath), $cachePath);
    }

    private function compute(ResolvedImage $image, string $cachePath): string
    {
        $absolutePath = $image->source->absolutePath();
        if (!extension_loaded('imagick') || !is_readable($absolutePath)) {
            return '';
        }

        try {
            $im = new Imagick();
            // libjpeg DCT-scaled decode: decodes JPEGs at roughly the requested
            // size instead of full resolution (~100ms+ for a multi-MP photo,
            // which dwarfs the quantize below). Ignored by non-JPEG decoders.
            $im->setOption('jpeg:size', '100x100');
            // [0]: first frame only. Without it an animation decodes every
            // frame (~28 MB per source megapixel).
            $im->readImage($absolutePath . '[0]');
            // Tiny working copy so quantize is fast on 6000×4000 sources.
            $im->scaleImage(50, 0);
            if (self::hasTransparency($im)) {
                $im->clear();
                rex_file::put($cachePath, self::NO_COLOR);
                return '';
            }
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
            // Park it: a decode error is usually fast and permanent (corrupt
            // file, format the ImageMagick build cannot read), so every render
            // would otherwise retry and log it again.
            RenderBudget::rememberFailure($cachePath);
            return '';
        }

        rex_file::put($cachePath, $hex);
        return $hex;
    }

    private static function hasTransparency(Imagick $im): bool
    {
        if (!$im->getImageAlphaChannel()) {
            return false;
        }
        $opaque = Imagick::getQuantumRange()['quantumRangeLong'];
        // Measured on the 50 px working copy on purpose: each of its pixels
        // averages 1/50 of the image width, so the minimum says how much of
        // the most transparent spot is see-through. Under 5 % (a stray
        // semi-transparent edge row from an export, a single pixel) nothing
        // shows at display size, and the image keeps its colour.
        return $im->getImageChannelRange(Imagick::CHANNEL_ALPHA)['minima'] < $opaque * self::OPAQUE_ENOUGH;
    }

    public static function cachePathFor(SourceInterface $source): string
    {
        $hash = hash('xxh64', $source->key() . ':' . $source->cacheBust() . ':' . self::CACHE_VERSION);
        return rex_path::addonAssets(
            Config::ADDON,
            'cache/_color/' . substr($hash, 0, 2) . '/' . $hash . '.txt',
        );
    }
}
