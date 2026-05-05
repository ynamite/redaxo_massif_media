<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Pipeline;

use PHPUnit\Framework\TestCase;
use rex_path;
use Ynamite\Media\Config;
use Ynamite\Media\Pipeline\CacheInvalidator;
use Ynamite\Media\Pipeline\DominantColor;
use Ynamite\Media\Pipeline\MetadataReader;
use Ynamite\Media\Pipeline\Placeholder;

/**
 * Per-asset cache invalidation: when a media-pool entry changes, the four
 * cache locations (variants dir, meta, lqip, color) for that filename should
 * vanish, while sibling assets remain intact.
 */
final class CacheInvalidatorTest extends TestCase
{
    private string $tmpBase;
    private string $cacheDir;
    private string $mediaDir;

    protected function setUp(): void
    {
        $this->tmpBase = sys_get_temp_dir() . '/massif_invalidator_' . uniqid('', true);
        $this->cacheDir = $this->tmpBase . '/assets/addons/' . Config::ADDON . '/cache';
        $this->mediaDir = $this->tmpBase . '/media';
        rex_path::_setBase($this->tmpBase);
        @mkdir($this->cacheDir, 0777, true);
        @mkdir($this->mediaDir, 0777, true);
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

    private function seedSourceFile(string $filename, int $mtime): void
    {
        $abs = $this->mediaDir . '/' . $filename;
        @mkdir(dirname($abs), 0777, true);
        file_put_contents($abs, 'fake-source-bytes');
        touch($abs, $mtime);
    }

    private function seedFile(string $absPath, string $content = 'x'): void
    {
        @mkdir(dirname($absPath), 0777, true);
        file_put_contents($absPath, $content);
    }

    public function testInvalidatesAllFourBucketsForFocalPointEdit(): void
    {
        // Focal-point edit: file on disk is untouched, mtime stable. We seed
        // cache entries at the *current* mtime hash, then call invalidate().
        // All four buckets should disappear.
        $filename = 'foo.jpg';
        $mtime = 1_700_000_000;
        $this->seedSourceFile($filename, $mtime);

        $variantPath = $this->cacheDir . '/' . $filename . '/webp-1200-q80.webp';
        $animatedPath = $this->cacheDir . '/' . $filename . '/animated.webp';
        $metaPath = MetadataReader::metaCachePath($filename, $mtime);
        $lqipPath = Placeholder::cachePathFor($filename, $mtime);
        $colorPath = DominantColor::cachePathFor($filename, $mtime);

        $this->seedFile($variantPath, 'webp-bytes');
        $this->seedFile($animatedPath, 'animated-bytes');
        $this->seedFile($metaPath, '{"width":1024}');
        $this->seedFile($lqipPath, 'data:image/webp;base64,AAAA');
        $this->seedFile($colorPath, '#abcdef');

        CacheInvalidator::invalidate($filename);

        self::assertFileDoesNotExist($variantPath);
        self::assertFileDoesNotExist($animatedPath);
        self::assertDirectoryDoesNotExist(dirname($variantPath));
        self::assertFileDoesNotExist($metaPath);
        self::assertFileDoesNotExist($lqipPath);
        self::assertFileDoesNotExist($colorPath);
    }

    public function testLeavesSiblingAssetsUntouched(): void
    {
        $foo = 'foo.jpg';
        $bar = 'bar.jpg';
        $mtime = 1_700_000_000;
        $this->seedSourceFile($foo, $mtime);
        $this->seedSourceFile($bar, $mtime);

        $fooVariant = $this->cacheDir . '/' . $foo . '/webp-1200-q80.webp';
        $barVariant = $this->cacheDir . '/' . $bar . '/webp-1200-q80.webp';
        $fooMeta = MetadataReader::metaCachePath($foo, $mtime);
        $barMeta = MetadataReader::metaCachePath($bar, $mtime);
        $fooLqip = Placeholder::cachePathFor($foo, $mtime);
        $barLqip = Placeholder::cachePathFor($bar, $mtime);
        $fooColor = DominantColor::cachePathFor($foo, $mtime);
        $barColor = DominantColor::cachePathFor($bar, $mtime);

        foreach ([$fooVariant, $barVariant, $fooMeta, $barMeta, $fooLqip, $barLqip, $fooColor, $barColor] as $p) {
            $this->seedFile($p);
        }

        CacheInvalidator::invalidate($foo);

        self::assertFileDoesNotExist($fooVariant);
        self::assertFileDoesNotExist($fooMeta);
        self::assertFileDoesNotExist($fooLqip);
        self::assertFileDoesNotExist($fooColor);

        self::assertFileExists($barVariant, 'bar.jpg variant must survive foo.jpg invalidation');
        self::assertFileExists($barMeta, 'bar.jpg meta must survive foo.jpg invalidation');
        self::assertFileExists($barLqip, 'bar.jpg lqip must survive foo.jpg invalidation');
        self::assertFileExists($barColor, 'bar.jpg color must survive foo.jpg invalidation');
    }

    public function testHandlesDeletedSourceFile(): void
    {
        // MEDIA_DELETED: the file is gone by the time we run. We can't compute
        // the original meta hash (that would need the old mtime). The
        // path-keyed variants directory is still removable by name, which is
        // the bulky part. Hash-keyed entries become orphans — accepted.
        $filename = 'gone.jpg';
        $variantPath = $this->cacheDir . '/' . $filename . '/webp-1200-q80.webp';
        $this->seedFile($variantPath, 'orphan-bytes');

        // No source file on disk → invalidate() must not throw.
        CacheInvalidator::invalidate($filename);

        self::assertFileDoesNotExist($variantPath);
        self::assertDirectoryDoesNotExist(dirname($variantPath));
    }

    public function testEmptyFilenameIsNoop(): void
    {
        // Defensive: empty string from a malformed EP payload must not, e.g.,
        // try to delete the entire cache directory via "cache/".
        $sentinel = $this->cacheDir . '/some.jpg/webp-1200-q80.webp';
        $this->seedFile($sentinel);

        CacheInvalidator::invalidate('');

        self::assertFileExists($sentinel);
    }

    public function testCurrentHashDeletionMissesOrphansAfterFileReplacement(): void
    {
        // Documents the "orphan" behaviour for file-replacement updates:
        // the OLD meta entry (keyed at OLD mtime) is left on disk because by
        // the time MEDIA_UPDATED fires the file already has the NEW mtime.
        // Correctness is intact (the new hash is fresh, next read produces a
        // clean entry). The variants directory IS wiped because it's
        // path-keyed. This test pins the trade-off so it's visible if anyone
        // tries to "fix" it without weighing the O(cache size) cost.
        $filename = 'replaced.jpg';
        $oldMtime = 1_700_000_000;
        $newMtime = 1_700_000_500;
        $this->seedSourceFile($filename, $newMtime);

        $oldMetaPath = MetadataReader::metaCachePath($filename, $oldMtime);
        $newMetaPath = MetadataReader::metaCachePath($filename, $newMtime);
        self::assertNotSame($oldMetaPath, $newMetaPath, 'old/new mtimes must hash differently');

        $variantPath = $this->cacheDir . '/' . $filename . '/webp-1200-q80.webp';
        $this->seedFile($oldMetaPath, '{"old":true}');
        $this->seedFile($variantPath, 'old-variant');

        CacheInvalidator::invalidate($filename);

        self::assertFileExists($oldMetaPath, 'old meta orphan is accepted (documented)');
        self::assertFileDoesNotExist($variantPath, 'variants directory always wiped');
    }
}
