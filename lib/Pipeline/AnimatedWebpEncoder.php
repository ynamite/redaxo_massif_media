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
 * Re-encodes an animated source (currently only animated GIF) into a single
 * animated WebP variant at intrinsic dimensions. Lives outside the Glide
 * pipeline because Glide's encoder writes a single frame and silently drops
 * the rest — animated outputs need Imagick `writeImages($path, true)` (the
 * `$adjoin = true` flag preserves all frames in the output container).
 *
 * Cache layout uses a flat one-segment stem so Endpoint::parseCachePath can
 * distinguish animated requests from regular Glide variants:
 *   cache/{src}/animated.webp
 * No width / quality / fit / filters in the path — there's only ever one
 * animated variant per source. Browsers that support WebP support animated
 * WebP, and the GIF fallback in the <picture> markup covers the rest.
 *
 * Bypasses Config::lqipEnabled / colorEnabled; this is a format-upgrade for
 * animated sources, not a placeholder. Always opportunistically generated
 * when the source is animated and Imagick is available.
 */
final class AnimatedWebpEncoder
{
    /**
     * Encode (or confirm cached) the animated WebP for a source. Returns the
     * absolute on-disk cache path, or '' when generation is impossible
     * (passthrough check failed, Imagick missing, write failed).
     */
    public function encode(ResolvedImage $image): string
    {
        if (!$this->shouldEncode($image)) {
            return '';
        }

        $cachePath = self::cacheFile($image->sourcePath);
        if (is_file($cachePath) && filesize($cachePath) > 0) {
            return $cachePath;
        }

        if (!extension_loaded('imagick') || !is_readable($image->absolutePath)) {
            return '';
        }

        try {
            $im = new Imagick();
            $im->readImage($image->absolutePath);
            // coalesceImages() materialises each frame as a full-canvas image,
            // which is what most WebP decoders expect. Without it some sources
            // produce broken output (frames overlap with prior-frame pixels).
            $coalesced = $im->coalesceImages();
            $coalesced->setImageFormat('webp');

            @mkdir(dirname($cachePath), 0777, true);
            $coalesced->writeImages($cachePath, true);

            $im->clear();
            $coalesced->clear();
        } catch (Throwable $e) {
            rex_logger::logException($e);
            return '';
        }

        return is_file($cachePath) && filesize($cachePath) > 0 ? $cachePath : '';
    }

    /**
     * Cache path the encoder writes to. Public so URL builder + Endpoint can
     * agree on the same on-disk location without duplicating the construction.
     */
    public static function cacheFile(string $sourcePath): string
    {
        return rex_path::addonAssets(
            Config::ADDON,
            'cache/' . self::cacheRelPath($sourcePath),
        );
    }

    /**
     * Cache-relative path (the part that ends up in URL `?p=` and in
     * Signature::sign payload). Single source of truth for the
     * `{src}/animated.webp` shape — Endpoint matches the same string when
     * routing animated requests.
     */
    public static function cacheRelPath(string $sourcePath): string
    {
        return $sourcePath . '/animated.webp';
    }

    private function shouldEncode(ResolvedImage $image): bool
    {
        // Only animated GIF for the v1 MVP. Animated PNG (apng) and animated
        // WebP-as-source aren't worth the markup branching until someone asks.
        return $image->isAnimated && $image->sourceFormat === 'gif';
    }
}
