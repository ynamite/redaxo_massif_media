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
        );
    }

    public static function metaCachePath(SourceInterface $source): string
    {
        $hash = hash('xxh64', $source->key() . ':' . $source->cacheBust());
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
        return is_array($json) ? $json : null;
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
            'is_animated' => $this->probeAnimated($absolutePath, $sourceFormat),
        ];
    }

    /**
     * Detect multi-frame sources. Only checked for formats that can carry
     * animation (gif, webp, png/apng) — other formats short-circuit to false
     * to avoid the Imagick open. Falls back to false if Imagick is unavailable
     * or the read fails (worst case: animated source treated as static, which
     * is the existing behaviour).
     */
    private function probeAnimated(string $absolutePath, string $sourceFormat): bool
    {
        if (!in_array($sourceFormat, ['gif', 'webp', 'png'], true)) {
            return false;
        }
        if (!extension_loaded('imagick')) {
            return false;
        }
        try {
            $im = new \Imagick();
            $im->pingImage($absolutePath);
            $count = $im->getNumberImages();
            $im->clear();
            return $count > 1;
        } catch (\Throwable) {
            return false;
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
