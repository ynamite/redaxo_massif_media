<?php

declare(strict_types=1);

namespace Ynamite\Media\Pipeline;

use FilesystemIterator;
use rex_file;
use rex_path;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use Ynamite\Media\Config;

/**
 * Recursive du + categorisation of the addon's cache directory. Used by the
 * Sicherheit & Cache backend tab to surface disk usage without anyone having
 * to ssh in.
 *
 * Memoized to `cache/_stats.json` for up to TTL seconds — recursive iteration
 * over a multi-thousand-file cache is cheap (sub-second) but adds up over a
 * burst of page loads, and the stats are rough guidance not realtime metrics.
 *
 * `rex_dir::delete($cacheDir, false)` (the existing "Cache leeren" action)
 * removes the memo file along with everything else, so the next visit after a
 * clear naturally recomputes from scratch.
 */
final class CacheStats
{
    /** Memo lifetime in seconds. Stats are advisory; staleness is acceptable. */
    private const TTL = 300;

    /**
     * @return array{
     *   computed_at: int,
     *   cache_dir: string,
     *   exists: bool,
     *   total_bytes: int,
     *   file_count: int,
     *   oldest_mtime: int|null,
     *   newest_mtime: int|null,
     *   by_kind: array<string, array{count: int, bytes: int}>
     * }
     */
    public function compute(bool $forceRefresh = false): array
    {
        $memoPath = self::memoPath();
        if (!$forceRefresh && is_file($memoPath)) {
            $raw = json_decode((string) file_get_contents($memoPath), true);
            if (is_array($raw)
                && isset($raw['computed_at'])
                && (time() - (int) $raw['computed_at']) < self::TTL
            ) {
                /** @var array $raw */
                return $raw;
            }
        }

        $stats = $this->walk();
        // Best-effort persist; if write fails (perms etc.) we just return
        // fresh stats and recompute next time.
        rex_file::put($memoPath, (string) json_encode($stats, JSON_UNESCAPED_SLASHES));
        return $stats;
    }

    public static function memoPath(): string
    {
        return rex_path::addonAssets(Config::ADDON, 'cache/_stats.json');
    }

    /** @return array<string, mixed> */
    private function walk(): array
    {
        $cacheDir = rex_path::addonAssets(Config::ADDON, 'cache/');
        $base = [
            'computed_at' => time(),
            'cache_dir' => $cacheDir,
            'exists' => is_dir($cacheDir),
            'total_bytes' => 0,
            'file_count' => 0,
            'oldest_mtime' => null,
            'newest_mtime' => null,
            'by_kind' => [
                'meta' => ['count' => 0, 'bytes' => 0],
                'lqip' => ['count' => 0, 'bytes' => 0],
                'color' => ['count' => 0, 'bytes' => 0],
                'animated' => ['count' => 0, 'bytes' => 0],
                'variants' => ['count' => 0, 'bytes' => 0],
            ],
        ];

        if (!$base['exists']) {
            return $base;
        }

        try {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $cacheDir,
                    FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS,
                ),
            );
        } catch (Throwable) {
            // Unreadable cache dir → return the empty shape, don't blow up the
            // settings page.
            return $base;
        }

        $oldest = null;
        $newest = null;

        /** @var SplFileInfo $file */
        foreach ($iter as $file) {
            if (!$file->isFile()) {
                continue;
            }
            // Don't count our own memo against the cache size.
            if ($file->getFilename() === '_stats.json') {
                continue;
            }
            $bytes = (int) $file->getSize();
            $mtime = (int) $file->getMTime();
            $base['total_bytes'] += $bytes;
            $base['file_count']++;

            if ($oldest === null || $mtime < $oldest) {
                $oldest = $mtime;
            }
            if ($newest === null || $mtime > $newest) {
                $newest = $mtime;
            }

            $kind = self::classify($file->getPathname(), $cacheDir);
            $base['by_kind'][$kind]['count']++;
            $base['by_kind'][$kind]['bytes'] += $bytes;
        }

        $base['oldest_mtime'] = $oldest;
        $base['newest_mtime'] = $newest;
        return $base;
    }

    private static function classify(string $path, string $cacheDir): string
    {
        $rel = ltrim(substr($path, strlen($cacheDir)), '/');
        if (str_starts_with($rel, '_meta/')) {
            return 'meta';
        }
        if (str_starts_with($rel, '_lqip/')) {
            return 'lqip';
        }
        if (str_starts_with($rel, '_color/')) {
            return 'color';
        }
        if (str_ends_with($rel, '/animated.webp')) {
            return 'animated';
        }
        return 'variants';
    }
}
