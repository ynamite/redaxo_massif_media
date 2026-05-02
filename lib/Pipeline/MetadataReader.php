<?php

declare(strict_types=1);

namespace Ynamite\Media\Pipeline;

use kornrunner\Blurhash\Blurhash;
use rex_file;
use rex_media;
use rex_path;
use Throwable;
use Ynamite\Media\Config;

final class MetadataReader
{
    public function read(string $filename, string $absolutePath, ?rex_media $media): ResolvedImage
    {
        $mtime = (int) (filemtime($absolutePath) ?: 0);

        $cached = $this->loadCachedMeta($filename, $mtime);
        if ($cached === null) {
            $cached = $this->computeMeta($filename, $absolutePath, $media);
            $this->saveCachedMeta($filename, $mtime, $cached);
        }

        return new ResolvedImage(
            sourcePath: $filename,
            absolutePath: $absolutePath,
            intrinsicWidth: (int) ($cached['width'] ?? 0),
            intrinsicHeight: (int) ($cached['height'] ?? 0),
            mime: (string) ($cached['mime'] ?? 'application/octet-stream'),
            sourceFormat: (string) ($cached['source_format'] ?? 'unknown'),
            focalPoint: isset($cached['focal']) && $cached['focal'] !== '' ? (string) $cached['focal'] : null,
            blurhash: isset($cached['blurhash']) && $cached['blurhash'] !== '' ? (string) $cached['blurhash'] : null,
            mtime: $mtime,
        );
    }

    public function metaCachePath(string $filename, int $mtime): string
    {
        $hash = hash('xxh64', $filename . ':' . $mtime);
        return rex_path::addonAssets(
            Config::ADDON,
            'cache/_meta/' . substr($hash, 0, 2) . '/' . $hash . '.json'
        );
    }

    private function loadCachedMeta(string $filename, int $mtime): ?array
    {
        $path = $this->metaCachePath($filename, $mtime);
        if (!is_file($path)) {
            return null;
        }
        $json = json_decode((string) file_get_contents($path), true);
        return is_array($json) ? $json : null;
    }

    private function saveCachedMeta(string $filename, int $mtime, array $meta): void
    {
        $path = $this->metaCachePath($filename, $mtime);
        rex_file::put($path, json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function computeMeta(string $filename, string $absolutePath, ?rex_media $media): array
    {
        [$width, $height, $mime] = $this->probeDimensionsAndMime($absolutePath);
        $sourceFormat = $this->formatFromMime($mime);

        $focal = null;
        if ($media !== null) {
            $raw = $media->getValue('med_focuspoint');
            if (is_string($raw) && $raw !== '') {
                $focal = $this->normalizeFocal($raw);
            }
        }

        $blurhash = null;
        if (Config::blurhashEnabled() && in_array($sourceFormat, ['jpg', 'png', 'webp'], true)) {
            $blurhash = $this->computeBlurhash($absolutePath);
        }

        return [
            'width' => $width,
            'height' => $height,
            'mime' => $mime,
            'source_format' => $sourceFormat,
            'focal' => $focal,
            'blurhash' => $blurhash,
        ];
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

    private function computeBlurhash(string $absolutePath): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }

        try {
            $bytes = @file_get_contents($absolutePath);
            if ($bytes === false) {
                return null;
            }
            $img = @imagecreatefromstring($bytes);
            if ($img === false) {
                return null;
            }

            $width = imagesx($img);
            $height = imagesy($img);

            $maxDim = 64;
            if ($width > $maxDim || $height > $maxDim) {
                $ratio = min($maxDim / $width, $maxDim / $height);
                $newWidth = max(1, (int) ($width * $ratio));
                $newHeight = max(1, (int) ($height * $ratio));
                $resized = imagecreatetruecolor($newWidth, $newHeight);
                imagecopyresampled($resized, $img, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                imagedestroy($img);
                $img = $resized;
                $width = $newWidth;
                $height = $newHeight;
            }

            $pixels = [];
            for ($y = 0; $y < $height; $y++) {
                $row = [];
                for ($x = 0; $x < $width; $x++) {
                    $color = imagecolorat($img, $x, $y);
                    $row[] = [
                        ($color >> 16) & 0xFF,
                        ($color >> 8) & 0xFF,
                        $color & 0xFF,
                    ];
                }
                $pixels[] = $row;
            }
            imagedestroy($img);

            return Blurhash::encode($pixels, 4, 3);
        } catch (Throwable) {
            return null;
        }
    }
}
