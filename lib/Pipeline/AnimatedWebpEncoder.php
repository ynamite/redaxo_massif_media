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
    public const BUSY = ':busy:';

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
     * (source/width rejected, WebP unavailable, write failed), or BUSY when locked.
     */
    public function encode(ResolvedImage $image, int $width, bool $wait = false): string
    {
        if (!Config::canServerEncode('webp') || !$image->isAnimatedEncodable() || !in_array($width, self::widths($image), true)) {
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

        $previousUmask = umask(0022);
        $lock = null;
        $tmp = null;
        try {
            // ponytail: one animated encode per host at a time; use a small semaphore if throughput matters.
            $lockPath = rex_path::addonData(Config::ADDON, 'animated-encode.lock');
            @mkdir(dirname($lockPath), 0755, true);
            $lock = @fopen($lockPath, 'c');
            if ($lock === false) {
                return '';
            }
            if (!flock($lock, LOCK_EX | ($wait ? 0 : LOCK_NB))) {
                return self::BUSY;
            }
            clearstatcache(true, $cachePath);
            if (is_file($cachePath) && filesize($cachePath) > 0) {
                return $cachePath;
            }
            $tmp = $cachePath . '.' . getmypid() . '.' . bin2hex(random_bytes(4)) . '.tmp.webp';
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
            @mkdir(dirname($cachePath), 0755, true);
            // Write to a temp sibling, then rename: readers never see a partial file.
            $coalesced->writeImages($tmp, true);
            $coalesced->clear();
            if (!is_file($tmp) || filesize($tmp) === 0 || !rename($tmp, $cachePath)) {
                @unlink($tmp);
                return '';
            }
        } catch (Throwable $e) {
            rex_logger::logException($e);
            return '';
        } finally {
            if ($tmp !== null && is_file($tmp)) {
                @unlink($tmp);
            }
            foreach (['im', 'coalesced'] as $v) {
                if (isset($$v) && $$v instanceof Imagick) {
                    $$v->clear();
                }
            }
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            umask($previousUmask);
        }

        return is_file($cachePath) && filesize($cachePath) > 0 ? $cachePath : '';
    }

    public function prewarm(ResolvedImage $image): void
    {
        try {
            foreach (self::widths($image) as $width) {
                $this->encode($image, $width, wait: true);
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
