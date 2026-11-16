<?php

declare(strict_types=1);

namespace Ynamite\Media\Pipeline;

use rex_file;
use rex_path;
use Ynamite\Media\Config;
use Ynamite\Media\Source\MediapoolSource;
use Ynamite\Media\Source\SourceInterface;

final class MetadataReader
{
    public function read(SourceInterface $source): ResolvedImage
    {
        $cached = $this->loadCachedMeta($source);
        if ($cached === null) {
            $cached = $this->computeMeta($source);
            $this->saveCachedMeta($source, $cached);
        }

        return new ResolvedImage(
            source: $source,
            intrinsicWidth: (int) ($cached['width'] ?? 0),
            intrinsicHeight: (int) ($cached['height'] ?? 0),
            mime: (string) ($cached['mime'] ?? 'application/octet-stream'),
            sourceFormat: (string) ($cached['source_format'] ?? 'unknown'),
            focalPoint: isset($cached['focal']) && $cached['focal'] !== '' ? (string) $cached['focal'] : null,
            isAnimated: (bool) ($cached['is_animated'] ?? false),
            frameCount: (int) ($cached['frames'] ?? 1),
        );
    }

    public static function metaCachePath(SourceInterface $source): string
    {
        // Recompute pre-frame-count sidecars after upgrading from 1.0.18.
        $hash = hash('xxh64', $source->key() . ':' . $source->cacheBust() . ':v2');
        return rex_path::addonAssets(
            Config::ADDON,
            'cache/_meta/' . substr($hash, 0, 2) . '/' . $hash . '.json'
        );
    }

    private function loadCachedMeta(SourceInterface $source): ?array
    {
        $path = self::metaCachePath($source);
        if (!is_file($path)) {
            return null;
        }
        $json = json_decode((string) file_get_contents($path), true);
        if (!is_array($json)) {
            return null;
        }

        // Expire by age. Failed reads (the `failed` sentinel set in computeMeta())
        // use the short sentinel TTL so a broken asset is re-probed soon instead of
        // staying stuck at 0×0 forever; good entries use the long metadata TTL.
        // A TTL of 0 disables the check (cache until explicitly invalidated).
        $ttl = !empty($json['failed'])
            ? Config::sentinelTtlSeconds()
            : Config::metadataTtlSeconds();
        if ($ttl > 0 && (time() - (int) @filemtime($path)) > $ttl) {
            return null;
        }

        return $json;
    }

    private function saveCachedMeta(SourceInterface $source, array $meta): void
    {
        $path = self::metaCachePath($source);
        rex_file::put($path, json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function computeMeta(SourceInterface $source): array
    {
        $absolutePath = $source->absolutePath();
        [$width, $height, $mime] = $this->probeDimensionsAndMime($absolutePath);
        $sourceFormat = $this->formatFromMime($mime);
        $frames = $this->probeFrameCount($absolutePath, $sourceFormat);

        // A genuinely unreadable asset reads as 0×0 with no identifiable format.
        // SVG also reads as 0×0 (no raster dimensions) but resolves to format 'svg'
        // via mime_content_type — it is NOT a failure and must keep the long
        // metadata TTL, so the discriminator hinges on the unknown format.
        $failed = $width === 0 && $height === 0 && $sourceFormat === 'unknown';

        $focal = null;
        if ($source instanceof MediapoolSource && $source->media !== null) {
            $raw = $source->media->getValue('med_focuspoint');
            if (is_string($raw) && $raw !== '') {
                $focal = $this->normalizeFocal($raw);
            }
        }

        return [
            'width' => $width,
            'height' => $height,
            'mime' => $mime,
            'source_format' => $sourceFormat,
            'focal' => $focal,
            'frames' => $frames,
            'is_animated' => $frames > 1,
            'failed' => $failed,
        ];
    }

    /**
     * Count frames only for formats that can carry animation (gif, webp,
     * png/apng). Other formats, unavailable Imagick and failed reads use 1.
     */
    private function probeFrameCount(string $absolutePath, string $sourceFormat): int
    {
        if (!in_array($sourceFormat, ['gif', 'webp', 'png'], true)) {
            return 1;
        }
        if (!extension_loaded('imagick')) {
            return 1;
        }
        try {
            $im = new \Imagick();
            $im->pingImage($absolutePath);
            $count = $im->getNumberImages();
            $im->clear();
            return $count;
        } catch (\Throwable) {
            return 1;
        }
    }

    /** @return array{0: int, 1: int, 2: string} */
    private function probeDimensionsAndMime(string $absolutePath): array
    {
        $info = @getimagesize($absolutePath);
        if ($info !== false) {
            return [(int) $info[0], (int) $info[1], (string) ($info['mime'] ?? '')];
        }
        $mime = function_exists('mime_content_type') ? (string) (@mime_content_type($absolutePath) ?: '') : '';
        return [0, 0, $mime];
    }

    private function formatFromMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            'image/gif' => 'gif',
            'image/svg+xml', 'image/svg' => 'svg',
            default => 'unknown',
        };
    }

    /**
     * Accept a focal-point value from focuspoint addon and normalize to "X% Y%".
     * Handles: "50% 30%", "50,30", "0.5,0.3", "50;30", JSON {"x":..,"y":..}.
     */
    public function normalizeFocal(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if ($value[0] === '{') {
            $json = json_decode($value, true);
            if (is_array($json) && isset($json['x'], $json['y'])) {
                return $this->formatFocal((float) $json['x'], (float) $json['y']);
            }
        }

        if (preg_match('/^([-+]?[0-9.]+)\s*[,;\s]\s*([-+]?[0-9.]+)$/', $value, $m)) {
            return $this->formatFocal((float) $m[1], (float) $m[2]);
        }

        if (preg_match('/^[\d.]+%\s+[\d.]+%$/', $value)) {
            return $value;
        }

        return null;
    }

    public function formatFocal(float $x, float $y): string
    {
        if ($x <= 1.0 && $y <= 1.0) {
            $x *= 100;
            $y *= 100;
        }
        $x = max(0.0, min(100.0, $x));
        $y = max(0.0, min(100.0, $y));
        return sprintf('%g%% %g%%', $x, $y);
    }
}
