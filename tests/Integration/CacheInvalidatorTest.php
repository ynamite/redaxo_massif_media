<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Integration;

use PHPUnit\Framework\TestCase;
use rex_path;
use Ynamite\Media\Config;
use Ynamite\Media\Pipeline\CacheInvalidator;
use Ynamite\Media\Pipeline\MetadataReader;
use Ynamite\Media\Pipeline\ResolvedImage;
use Ynamite\Media\Source\MediapoolSource;

/**
 * End-to-end check: after MetadataReader writes meta.json for a fixture,
 * CacheInvalidator::invalidate removes it, and the next read regenerates a
 * fresh entry. Mirrors the production flow (MEDIA_UPDATED → invalidate → next
 * frontend render reads fresh meta) without bringing in REDAXO's extension
 * system.
 */
final class CacheInvalidatorTest extends TestCase
{
    private MetadataReader $reader;
    private string $fixturesDir;
    private string $tmpBase;

    protected function setUp(): void
    {
        $this->reader = new MetadataReader();
        $this->fixturesDir = __DIR__ . '/../_fixtures';
        $this->tmpBase = sys_get_temp_dir() . '/massif_invalidator_int_' . uniqid('', true);
        rex_path::_setBase($this->tmpBase);
        @mkdir($this->tmpBase . '/media', 0777, true);
        // Stage a copy of the fixture under the tmp media dir so rex_path::media()
        // resolves to a real file with a stable mtime.
        copy(
            $this->fixturesDir . '/landscape-800x600.jpg',
            $this->tmpBase . '/media/landscape-800x600.jpg',
        );
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

    public function testReadWriteThenInvalidateRemovesMetaCache(): void
    {
        $filename = 'landscape-800x600.jpg';
        $absolutePath = $this->tmpBase . '/media/' . $filename;
        $mtime = (int) filemtime($absolutePath);
        $source = new MediapoolSource(filename: $filename, absolutePath: $absolutePath, mtime: $mtime);

        $first = $this->reader->read($source);
        self::assertSame(800, $first->intrinsicWidth);
        self::assertInstanceOf(ResolvedImage::class, $first);

        $metaPath = MetadataReader::metaCachePath($source);
        self::assertFileExists($metaPath, 'first read should persist meta.json');

        // Seed a variant alongside meta to confirm the directory is wiped too.
        $variantPath = rex_path::addonAssets(Config::ADDON, 'cache/' . $filename . '/webp-1200-q80.webp');
        @mkdir(dirname($variantPath), 0777, true);
        file_put_contents($variantPath, 'fake-variant-bytes');

        CacheInvalidator::invalidate($filename);

        self::assertFileDoesNotExist($metaPath);
        self::assertFileDoesNotExist($variantPath);
        self::assertDirectoryDoesNotExist(dirname($variantPath));

        // Next read regenerates cleanly.
        $second = $this->reader->read($source);
        self::assertSame(800, $second->intrinsicWidth);
        self::assertFileExists($metaPath, 'follow-up read should rewrite meta.json');
    }
}
