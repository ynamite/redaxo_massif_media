<?php

declare(strict_types=1);

namespace Ynamite\Media\Pipeline;

use Imagick;
use rex_logger;
use rex_path;
use Throwable;
use Ynamite\Media\Config;

/**
 * Re-encodes animated GIF/WebP sources at up to three widths outside the Glide
 * pipeline because Glide's encoder writes a single frame and silently drops
 * the rest — animated outputs need Imagick `writeImages($path, true)` (the
 * `$adjoin = true` flag preserves all frames in the output container).
 *
 * The intrinsic width keeps cache/{src}/animated.webp for existing URLs;
 * smaller widths use cache/{src}/animated-{width}.webp.
 */
final class AnimatedWebpEncoder
{
    /** @return list<int> */
    public static function widths(ResolvedImage $image): array
    {
        $widths = [];
        foreach ([640, 1280] as $width) {
            if ($width < $image->intrinsicWidth) {
                $widths[] = $width;
            }
        }
        $widths[] = $image->intrinsicWidth;

        return $widths;
    }

    /**
     * Encode (or confirm cached) the animated WebP for a source. Returns the
     * absolute on-disk cache path, or '' when generation is impossible
     * (source/width rejected, Imagick missing, write failed).
     */
    public function encode(ResolvedImage $image, int $width): string
    {
        if (!$image->isAnimatedEncodable() || !in_array($width, self::widths($image), true)) {
            return '';
        }

        $cachePath = self::cacheFile($image->source->key(), $width, $image->intrinsicWidth);
        if (is_file($cachePath) && filesize($cachePath) > 0) {
            return $cachePath;
        }

        $absolutePath = $image->source->absolutePath();
        if (!extension_loaded('imagick') || !is_readable($absolutePath)) {
            return '';
        }

        $tmp = $cachePath . '.' . getmypid() . '.' . bin2hex(random_bytes(4)) . '.tmp.webp';
        try {
            $im = new Imagick();
            $im->readImage($absolutePath);
            // coalesceImages() materialises each frame as a full-canvas image,
            // which is what most WebP decoders expect. Without it some sources
            // produce broken output (frames overlap with prior-frame pixels).
            $coalesced = $im->coalesceImages();
            $im->clear();
            $coalesced->setOption('webp:method', '4');
            foreach ($coalesced as $frame) {
                if ($width < $image->intrinsicWidth) {
                    $frame->resizeImage($width, 0, Imagick::FILTER_LANCZOS, 1);
                    $frame->setImagePage(0, 0, 0, 0);
                }
                $frame->setImageFormat('webp');
                $frame->setImageCompressionQuality(Config::quality('webp'));
            }
            $output = $coalesced->deconstructImages();
            $coalesced->clear();

            @mkdir(dirname($cachePath), 0777, true);
            // Write to a temp sibling, then rename: readers never see a partial file.
            $output->writeImages($tmp, true);
            $output->clear();
            if (!is_file($tmp) || filesize($tmp) === 0 || !rename($tmp, $cachePath)) {
                @unlink($tmp);
                return '';
            }
        } catch (Throwable $e) {
            rex_logger::logException($e);
            @unlink($tmp);
            return '';
        } finally {
            foreach (['im', 'coalesced', 'output'] as $v) {
                if (isset($$v) && $$v instanceof Imagick) {
                    $$v->clear();
                }
            }
        }

        return is_file($cachePath) && filesize($cachePath) > 0 ? $cachePath : '';
    }

    public function prewarm(ResolvedImage $image): void
    {
        try {
            foreach (self::widths($image) as $width) {
                $this->encode($image, $width);
            }
        } catch (Throwable $e) {
            rex_logger::logException($e);
        }
    }

    /**
     * Cache path the encoder writes to. Public so URL builder + Endpoint can
     * agree on the same on-disk location without duplicating the construction.
     */
    public static function cacheFile(string $sourceKey, int $width, int $intrinsicWidth): string
    {
        return rex_path::addonAssets(
            Config::ADDON,
            'cache/' . self::cacheRelPath($sourceKey, $width, $intrinsicWidth),
        );
    }

    /**
     * Cache-relative path (the part that ends up in URL `?p=` and in
     * Signature::sign payload). Single source of truth for the
     * animated variant names, including the legacy intrinsic-width name.
     */
    public static function cacheRelPath(string $sourceKey, int $width, int $intrinsicWidth): string
    {
        return $sourceKey . ($width === $intrinsicWidth ? '/animated.webp' : '/animated-' . $width . '.webp');
    }
}
