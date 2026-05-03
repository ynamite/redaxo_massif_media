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

    public function testDetectsAnimatedGif(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('isAnimated detection needs Imagick.');
        }

        $resolved = $this->reader->read(
            'animated-3frame.gif',
            $this->fixturesDir . '/animated-3frame.gif',
            null,
        );

        self::assertSame('gif', $resolved->sourceFormat);
        self::assertTrue($resolved->isAnimated, 'Animated GIF fixture has 3 frames; isAnimated should be true.');
    }

    public function testStaticGifHasIsAnimatedFalse(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('isAnimated detection needs Imagick.');
        }

        $resolved = $this->reader->read(
            'tiny-32x32.gif',
            $this->fixturesDir . '/tiny-32x32.gif',
            null,
        );

        self::assertSame('gif', $resolved->sourceFormat);
        self::assertFalse($resolved->isAnimated);
    }

    public function testJpegIsAnimatedFalse(): void
    {
        $resolved = $this->reader->read(
            'landscape-800x600.jpg',
            $this->fixturesDir . '/landscape-800x600.jpg',
            null,
        );

        self::assertFalse($resolved->isAnimated, 'JPEG short-circuits the probe and stays false.');
    }
}
