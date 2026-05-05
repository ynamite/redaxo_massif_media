<?php

declare(strict_types=1);

namespace Ynamite\Media\Pipeline;

use rex_dir;
use rex_file;
use rex_path;
use Ynamite\Media\Config;

/**
 * Per-asset cache invalidation. Wired up to MEDIA_UPDATED / MEDIA_DELETED in
 * boot.php so editor actions in the media pool (focal-point edits, file
 * replacements, deletions) drop the now-stale cache instead of waiting for a
 * global "Cache leeren" sweep.
 *
 * The motivating case is focal-point updates: changing `med_focuspoint` writes
 * a database column, doesn't touch the file mtime, so the meta-cache hash
 * (xxh64 of filename + mtime) stays the same and `meta.json` keeps returning
 * the old focal point. The URL builder then bakes the old `cover-X-Y` token
 * into emitted markup and the browser hits the same cached variant. Wiping the
 * meta entry plus the path-keyed variants directory forces a clean rebuild on
 * the next render.
 *
 * For file-replacement updates, mtime has changed by the time the hook fires,
 * so the "current" hash points at a future entry. The old meta/lqip/color
 * entries are left orphaned — accepted: each is 50 bytes – 2 KB, and the bulky
 * variants directory is wiped wholesale. CacheStats can prune the rest.
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

        rex_file::delete(MetadataReader::metaCachePath($filename, $mtime));
        rex_file::delete(Placeholder::cachePathFor($filename, $mtime));
        rex_file::delete(DominantColor::cachePathFor($filename, $mtime));

        $variantsDir = rex_path::addonAssets(Config::ADDON, 'cache/' . $filename);
        if (is_dir($variantsDir)) {
            rex_dir::delete($variantsDir, true);
        }
    }
}
