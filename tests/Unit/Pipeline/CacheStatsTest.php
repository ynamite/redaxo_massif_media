<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Pipeline;

use PHPUnit\Framework\TestCase;
use rex_path;
use Ynamite\Media\Config;
use Ynamite\Media\Pipeline\CacheStats;

/**
 * Cache stats walker on a synthetic in-memory cache layout. Verifies the
 * categorisation rules (lqip / color / meta / animated / variants) and the
 * memo / TTL behaviour.
 */
final class CacheStatsTest extends TestCase
{
    private string $tmpBase;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->tmpBase = sys_get_temp_dir() . '/massif_cachestats_' . uniqid('', true);
        $this->cacheDir = $this->tmpBase . '/assets/addons/' . Config::ADDON . '/cache';
        rex_path::_setBase($this->tmpBase);
        @mkdir($this->cacheDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpBase)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmpBase, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($items as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->tmpBase);
        }
    }

    private function writeFile(string $relPath, int $size, ?int $mtime = null): void
    {
        $abs = $this->cacheDir . '/' . ltrim($relPath, '/');
        @mkdir(dirname($abs), 0777, true);
        file_put_contents($abs, str_repeat('x', $size));
        if ($mtime !== null) {
            touch($abs, $mtime);
        }
    }

    public function testEmptyCacheReportsZeros(): void
    {
        $stats = (new CacheStats())->compute();

        self::assertTrue($stats['exists']);
        self::assertSame(0, $stats['file_count']);
        self::assertSame(0, $stats['total_bytes']);
        self::assertNull($stats['oldest_mtime']);
        self::assertNull($stats['newest_mtime']);
    }

    public function testMissingCacheDirReportsExistsFalse(): void
    {
        // Use a fresh base where the cache dir was never created.
        $unset = sys_get_temp_dir() . '/massif_cachestats_missing_' . uniqid('', true);
        rex_path::_setBase($unset);

        $stats = (new CacheStats())->compute();

        self::assertFalse($stats['exists']);
        self::assertSame(0, $stats['file_count']);
    }

    public function testCategorisesByKindCorrectly(): void
    {
        $this->writeFile('_meta/ab/abcdef.json', 100);
        $this->writeFile('_lqip/cd/cdef00.txt', 600);
        $this->writeFile('_lqip/cd/cdef01.txt', 500);
        $this->writeFile('_color/ef/ef0000.txt', 7);
        $this->writeFile('hero.jpg/avif-1080-50.avif', 50_000);
        $this->writeFile('hero.jpg/webp-1080-75.webp', 80_000);
        $this->writeFile('subdir/spinner.gif/animated.webp', 40_000);

        $stats = (new CacheStats())->compute(forceRefresh: true);

        self::assertSame(7, $stats['file_count']);
        // 100 (meta) + 1100 (lqip) + 7 (color) + 130_000 (variants) + 40_000 (animated)
        self::assertSame(171_207, $stats['total_bytes']);
        self::assertSame(1, $stats['by_kind']['meta']['count']);
        self::assertSame(100, $stats['by_kind']['meta']['bytes']);
        self::assertSame(2, $stats['by_kind']['lqip']['count']);
        self::assertSame(1100, $stats['by_kind']['lqip']['bytes']);
        self::assertSame(1, $stats['by_kind']['color']['count']);
        self::assertSame(7, $stats['by_kind']['color']['bytes']);
        self::assertSame(1, $stats['by_kind']['animated']['count']);
        self::assertSame(40_000, $stats['by_kind']['animated']['bytes']);
        self::assertSame(2, $stats['by_kind']['variants']['count']);
        self::assertSame(130_000, $stats['by_kind']['variants']['bytes']);
    }

    public function testStatsMemoIsExcludedFromTotals(): void
    {
        $this->writeFile('hero.jpg/avif-800-50.avif', 1000);
        // Compute once → writes _stats.json
        $first = (new CacheStats())->compute();
        self::assertSame(1, $first['file_count']);

        // Force a fresh recompute — _stats.json now exists on disk but must
        // not count toward the totals.
        $second = (new CacheStats())->compute(forceRefresh: true);
        self::assertSame(1, $second['file_count']);
        self::assertSame(1000, $second['total_bytes']);
    }

    public function testReturnsMemoizedStatsWithinTtl(): void
    {
        $this->writeFile('hero.jpg/avif-800-50.avif', 1000);
        $first = (new CacheStats())->compute();
        $firstComputedAt = $first['computed_at'];

        // Add another file that the second call must NOT see (because we hit
        // the memo).
        $this->writeFile('hero.jpg/webp-800-75.webp', 2000);
        $second = (new CacheStats())->compute();

        self::assertSame($firstComputedAt, $second['computed_at']);
        self::assertSame(1, $second['file_count']);
    }

    public function testForceRefreshBypassesMemo(): void
    {
        $this->writeFile('hero.jpg/avif-800-50.avif', 1000);
        (new CacheStats())->compute();

        $this->writeFile('hero.jpg/webp-800-75.webp', 2000);
        $refreshed = (new CacheStats())->compute(forceRefresh: true);

        self::assertSame(2, $refreshed['file_count']);
        self::assertSame(3000, $refreshed['total_bytes']);
    }

    public function testTracksOldestAndNewestMtime(): void
    {
        $this->writeFile('hero.jpg/avif-800-50.avif', 100, mtime: 1_000_000);
        $this->writeFile('hero.jpg/webp-800-75.webp', 100, mtime: 2_000_000);
        $this->writeFile('hero.jpg/jpg-800-80.jpg', 100, mtime: 3_000_000);

        $stats = (new CacheStats())->compute(forceRefresh: true);

        self::assertSame(1_000_000, $stats['oldest_mtime']);
        self::assertSame(3_000_000, $stats['newest_mtime']);
    }
}
