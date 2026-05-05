<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Integration;

use PHPUnit\Framework\TestCase;
use Ynamite\Media\Pipeline\MetadataReader;
use Ynamite\Media\Source\MediapoolSource;

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

    private function source(string $filename): MediapoolSource
    {
        $absolutePath = $this->fixturesDir . '/' . $filename;
        return new MediapoolSource(
            filename: $filename,
            absolutePath: $absolutePath,
            mtime: (int) (filemtime($absolutePath) ?: 0),
        );
    }

    public function testReadsLandscapeIntrinsicDims(): void
    {
        $resolved = $this->reader->read($this->source('landscape-800x600.jpg'));

        self::assertSame(800, $resolved->intrinsicWidth);
        self::assertSame(600, $resolved->intrinsicHeight);
        self::assertSame('image/jpeg', $resolved->mime);
        self::assertSame('jpg', $resolved->sourceFormat);
    }

    public function testReadsPortraitIntrinsicDims(): void
    {
        $resolved = $this->reader->read($this->source('portrait-600x800.jpg'));

        self::assertSame(600, $resolved->intrinsicWidth);
        self::assertSame(800, $resolved->intrinsicHeight);
    }

    public function testReadsPng(): void
    {
        $resolved = $this->reader->read($this->source('square-400x400.png'));

        self::assertSame(400, $resolved->intrinsicWidth);
        self::assertSame(400, $resolved->intrinsicHeight);
        self::assertSame('png', $resolved->sourceFormat);
    }

    public function testDetectsAnimatedGif(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('isAnimated detection needs Imagick.');
        }

        $resolved = $this->reader->read($this->source('animated-3frame.gif'));

        self::assertSame('gif', $resolved->sourceFormat);
        self::assertTrue($resolved->isAnimated, 'Animated GIF fixture has 3 frames; isAnimated should be true.');
    }

    public function testStaticGifHasIsAnimatedFalse(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('isAnimated detection needs Imagick.');
        }

        $resolved = $this->reader->read($this->source('tiny-32x32.gif'));

        self::assertSame('gif', $resolved->sourceFormat);
        self::assertFalse($resolved->isAnimated);
    }

    public function testJpegIsAnimatedFalse(): void
    {
        $resolved = $this->reader->read($this->source('landscape-800x600.jpg'));

        self::assertFalse($resolved->isAnimated, 'JPEG short-circuits the probe and stays false.');
    }
}
