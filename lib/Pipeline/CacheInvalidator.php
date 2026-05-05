<?php

declare(strict_types=1);

namespace Ynamite\Media\Pipeline;

use rex_dir;
use rex_file;
use rex_path;
use Ynamite\Media\Config;
use Ynamite\Media\Source\ExternalSource;
use Ynamite\Media\Source\MediapoolSource;

/**
 * Per-asset cache invalidation. Wired up to MEDIA_UPDATED / MEDIA_DELETED in
 * boot.php so editor actions in the media pool (focal-point edits, file
 * replacements, deletions) drop the now-stale cache instead of waiting for a
 * global "Cache leeren" sweep.
 *
 * The motivating case is focal-point updates: changing `med_focuspoint` writes
 * a database column, doesn't touch the file mtime, so the meta-cache hash
 * (xxh64 of source.key:cacheBust) stays the same and `meta.json` keeps
 * returning the old focal point. The URL builder then bakes the old
 * `cover-X-Y` token into emitted markup and the browser hits the same cached
 * variant. Wiping the meta entry plus the path-keyed variants directory
 * forces a clean rebuild on the next render.
 *
 * For file-replacement updates, mtime has changed by the time the hook fires,
 * so the "current" hash points at a future entry. The old meta/lqip/color
 * entries are left orphaned — accepted: each is 50 bytes – 2 KB, and the bulky
 * variants directory is wiped wholesale. CacheStats can prune the rest.
 *
 * External URLs (`_external/<hash>`) are TTL-driven; manual invalidation goes
 * through {@see CacheInvalidator::invalidateUrl()} which nukes the entire
 * per-bucket directory in one shot.
 */
final class CacheInvalidator
{
    public static function invalidate(string $filename): void
    {
        if ($filename === '') {
            return;
        }

        $absolutePath = rex_path::media($filename);
        $mtime = is_file($absolutePath) ? (int) (filemtime($absolutePath) ?: 0) : 0;

        // Build a transient MediapoolSource so the cache-path helpers can key
        // by source.key():cacheBust() the same way the read path does.
        $source = new MediapoolSource(
            filename: $filename,
            absolutePath: $absolutePath,
            mtime: $mtime,
        );

        rex_file::delete(MetadataReader::metaCachePath($source));
        rex_file::delete(Placeholder::cachePathFor($source));
        rex_file::delete(DominantColor::cachePathFor($source));

        $variantsDir = rex_path::addonAssets(Config::ADDON, 'cache/' . $filename);
        if (is_dir($variantsDir)) {
            rex_dir::delete($variantsDir, true);
        }
    }

    /**
     * Drop everything cached for an external URL — the per-bucket directory
     * (which contains `_origin.bin`, `_manifest.json`, all variants), plus
     * any `_meta` / `_lqip` / `_color` sidecars at the current `fetchedAt`.
     *
     * Only manual callers use this — there's no "external URL was modified"
     * EP because the URL is opaque to REDAXO. TTL refresh handles natural
     * invalidation; this method is for forced clears (admin button, code
     * path that knows the upstream changed).
     */
    public static function invalidateUrl(string $url): void
    {
        if ($url === '' || !str_contains($url, '://')) {
            return;
        }

        $hash = ExternalSource::hashFor($url);
        $bucketDir = rex_path::addonAssets(Config::ADDON, 'cache/_external/' . $hash);

        // Try to read the manifest first so we can drop the meta/lqip/color
        // sidecars keyed at the *current* fetchedAt (matches what the read
        // path would compute today). Manifest absent → only nuke the bucket.
        $manifestPath = $bucketDir . '/_manifest.json';
        if (is_file($manifestPath)) {
            $raw = json_decode((string) file_get_contents($manifestPath), true);
            if (is_array($raw) && isset($raw['fetchedAt'])) {
                $source = new ExternalSource(
                    url: $url,
                    hash: $hash,
                    absolutePath: $bucketDir . '/_origin.bin',
                    fetchedAt: (int) $raw['fetchedAt'],
                    etag: isset($raw['etag']) ? (string) $raw['etag'] : null,
                    remoteLastModified: isset($raw['lastModified']) ? (int) $raw['lastModified'] : null,
                    ttlSeconds: isset($raw['ttl']) ? (int) $raw['ttl'] : 0,
                );
                rex_file::delete(MetadataReader::metaCachePath($source));
                rex_file::delete(Placeholder::cachePathFor($source));
                rex_file::delete(DominantColor::cachePathFor($source));
            }
        }

        if (is_dir($bucketDir)) {
            rex_dir::delete($bucketDir, true);
        }
    }
}
