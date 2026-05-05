<?php

declare(strict_types=1);

namespace Ynamite\Media\Source;

use rex_file;
use rex_path;
use Ynamite\Media\Config;

/**
 * Read/write helper for the per-bucket `_manifest.json` sidecar that lives
 * alongside `_origin.bin` in `cache/_external/<hash>/`. The manifest carries
 * everything {@see ExternalSource} needs to reconstruct itself without a
 * fresh fetch — URL, fetched-at timestamp, ETag/Last-Modified for the next
 * conditional GET, and the TTL the source was created with.
 *
 * Also surfaces the cache-bucket directory path so {@see ExternalSourceFactory}
 * and {@see CacheInvalidator} agree on layout without duplicating the
 * `rex_path::addonAssets(..., 'cache/_external/<hash>')` construction.
 */
final class ExternalManifest
{
    /**
     * Absolute filesystem path of the per-bucket directory.
     */
    public static function bucketDir(string $hash): string
    {
        return rex_path::addonAssets(Config::ADDON, 'cache/_external/' . $hash);
    }

    /**
     * Absolute filesystem path of the origin body.
     */
    public static function originPath(string $hash): string
    {
        return self::bucketDir($hash) . '/_origin.bin';
    }

    /**
     * Absolute filesystem path of the manifest sidecar.
     */
    public static function manifestPath(string $hash): string
    {
        return self::bucketDir($hash) . '/_manifest.json';
    }

    /**
     * @return array{url: string, etag: ?string, lastModified: ?int, fetchedAt: int, ttl: int}|null
     *         null when the manifest is missing or unreadable
     */
    public static function read(string $hash): ?array
    {
        $path = self::manifestPath($hash);
        if (!is_file($path)) {
            return null;
        }
        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw) || !isset($raw['url'], $raw['fetchedAt'])) {
            return null;
        }
        return [
            'url' => (string) $raw['url'],
            'etag' => isset($raw['etag']) && $raw['etag'] !== '' ? (string) $raw['etag'] : null,
            'lastModified' => isset($raw['lastModified']) ? (int) $raw['lastModified'] : null,
            'fetchedAt' => (int) $raw['fetchedAt'],
            'ttl' => isset($raw['ttl']) ? (int) $raw['ttl'] : 0,
        ];
    }

    /**
     * @param array{url: string, etag: ?string, lastModified: ?int, fetchedAt: int, ttl: int} $data
     */
    public static function write(string $hash, array $data): void
    {
        rex_file::put(
            self::manifestPath($hash),
            (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        );
    }
}
