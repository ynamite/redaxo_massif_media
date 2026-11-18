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
 * The colour and LQIP sidecars are only dropped when the file itself was
 * replaced (`$fileReplaced`): both are pure functions of the image bytes
 * (LQIP additionally of config values folded into its cache key), not of
 * media metadata, and regenerating them costs an image decode. A replaced
 * file normally gets new keys through its mtime anyway; the explicit drop
 * covers a replacement that keeps the mtime. A deleted file leaves two tiny
 * orphans (7 bytes / ~600 bytes) that only the full {@see clear()} removes.
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
    /** Per-bucket files in `_external/<hash>/` that keep mode preserves. */
    private const EXTERNAL_KEPT_FILES = ['_origin.bin', '_manifest.json'];

    /**
     * Wipe the addon cache (the `cache/` directory itself stays) and bump the
     * cache-generation token so already-emitted URLs get a new `&g=` segment.
     *
     * `$keepSourceDerived = true` (generic REDAXO clear, settings save) keeps
     * what a clear cannot cure and what is expensive to rebuild: `_color/`,
     * `_lqip/`, `_meta/` (pure functions of the image bytes plus, for LQIP,
     * config that is part of its key) and each external bucket's fetched origin +
     * manifest (staleness is handled by TTL / ETag revalidation). Kept
     * manifests are not rewritten. `false` (the addon's own clear button)
     * deletes everything — the escape hatch.
     */
    public static function clear(bool $keepSourceDerived): void
    {
        $cacheDir = rex_path::addonAssets(Config::ADDON, 'cache/');
        if (is_dir($cacheDir)) {
            if ($keepSourceDerived) {
                self::clearKeepingSourceDerived($cacheDir);
            } else {
                rex_dir::delete($cacheDir, false);
            }
        }
        Config::bumpCacheGeneration();
    }

    private static function clearKeepingSourceDerived(string $cacheDir): void
    {
        foreach (self::entries($cacheDir) as $entry) {
            $name = basename($entry);
            if ($name === '_color' || $name === '_lqip' || $name === '_meta') {
                continue;
            }
            if ($name !== '_external' || !self::isRealDir($entry)) {
                self::deleteEntry($entry);
                continue;
            }
            foreach (self::entries($entry) as $bucket) {
                if (!self::isRealDir($bucket)) {
                    self::deleteEntry($bucket);
                    continue;
                }
                foreach (self::entries($bucket) as $item) {
                    if (!is_file($item) || is_link($item) || !in_array(basename($item), self::EXTERNAL_KEPT_FILES, true)) {
                        self::deleteEntry($item);
                    }
                }
            }
        }
    }

    /**
     * Direct children of a directory, dotfiles included (glob('*') skips them).
     *
     * @return list<string>
     */
    private static function entries(string $dir): array
    {
        $dir = rtrim($dir, '/');
        $paths = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $paths[] = $dir . '/' . $name;
            }
        }
        return $paths;
    }

    /**
     * Symlinks are removed as links, never descended into — following one
     * would delete files outside the cache.
     */
    private static function deleteEntry(string $path): void
    {
        if (is_link($path)) {
            @unlink($path);
            return;
        }
        is_dir($path) ? rex_dir::delete($path, true) : rex_file::delete($path);
    }

    private static function isRealDir(string $path): bool
    {
        return is_dir($path) && !is_link($path);
    }

    public static function invalidate(string $filename, bool $fileReplaced = false): void
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
        foreach ([Placeholder::cachePathFor($source), DominantColor::cachePathFor($source)] as $sidecar) {
            // Any invalidation is a reason to retry a parked slow failure.
            RenderBudget::forgetFailure($sidecar);
            if ($fileReplaced) {
                // New bytes under an unchanged mtime (same-second replacement,
                // mtime-preserving import) keep the cache keys — without this
                // the placeholders of the old bytes would be served
                // indefinitely.
                rex_file::delete($sidecar);
            }
        }

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
                foreach ([Placeholder::cachePathFor($source), DominantColor::cachePathFor($source)] as $sidecar) {
                    rex_file::delete($sidecar);
                    RenderBudget::forgetFailure($sidecar);
                }
            }
        }

        if (is_dir($bucketDir)) {
            rex_dir::delete($bucketDir, true);
        }
    }
}
