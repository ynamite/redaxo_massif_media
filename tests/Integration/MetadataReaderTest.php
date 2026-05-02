<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Integration;

use PHPUnit\Framework\TestCase;
use Ynamite\Media\Pipeline\MetadataReader;

final class MetadataReaderTest extends TestCase
{
    private MetadataReader $reader;
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->reader = new MetadataReader();
        $this->fixturesDir = __DIR__ . '/../_fixtures';
        \rex_path::_setBase(sys_get_temp_dir() . '/massif_media_meta_' . uniqid('', true));
    }

    public function testReadsLandscapeIntrinsicDims(): void
    {
        $resolved = $this->reader->read(
            'landscape-800x600.jpg',
            $this->fixturesDir . '/landscape-800x600.jpg',
            null,
        );

        self::assertSame(800, $resolved->intrinsicWidth);
        self::assertSame(600, $resolved->intrinsicHeight);
        self::assertSame('image/jpeg', $resolved->mime);
        self::assertSame('jpg', $resolved->sourceFormat);
    }

    public function testReadsPortraitIntrinsicDims(): void
    {
        $resolved = $this->reader->read(
            'portrait-600x800.jpg',
            $this->fixturesDir . '/portrait-600x800.jpg',
            null,
        );

        self::assertSame(600, $resolved->intrinsicWidth);
        self::assertSame(800, $resolved->intrinsicHeight);
    }

    public function testReadsPng(): void
    {
        $resolved = $this->reader->read(
            'square-400x400.png',
            $this->fixturesDir . '/square-400x400.png',
            null,
        );

        self::assertSame(400, $resolved->intrinsicWidth);
        self::assertSame(400, $resolved->intrinsicHeight);
        self::assertSame('png', $resolved->sourceFormat);
    }

    public function testBlurhashGeneratedForRasterFormats(): void
    {
        if (!function_exists('imagecreatefromstring')) {
            self::markTestSkipped('GD not available');
        }
        \rex_config::set('massif_media', 'blurhash_enabled', 1);

        $resolved = $this->reader->read(
            'landscape-800x600.jpg',
            $this->fixturesDir . '/landscape-800x600.jpg',
            null,
        );

        self::assertNotNull($resolved->blurhash);
        // Blurhash strings are typically 6+ chars and start with one of the
        // base83 chars [a-zA-Z0-9#$%*+,\-.:;=?@\[\]^_{|}~].
        self::assertGreaterThanOrEqual(6, strlen($resolved->blurhash));
    }
}
