<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Integration;

use PHPUnit\Framework\TestCase;
use rex_config;
use rex_file;
use Ynamite\Media\Config;
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

    protected function tearDown(): void
    {
        rex_config::_reset();
    }

    /** @return array<string, mixed> */
    private function readSidecar(MediapoolSource $source): array
    {
        $json = json_decode((string) file_get_contents(MetadataReader::metaCachePath($source)), true);

        return is_array($json) ? $json : [];
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

    public function testGoodSidecarReusedWithinMetadataTtl(): void
    {
        $source = $this->source('landscape-800x600.jpg');

        // Prime the sidecar, then overwrite it with a sentinel width that recompute
        // could never produce. Within the (default, long) metadata TTL the cached
        // value must win — proving no recompute happened.
        $this->reader->read($source);
        rex_file::put(MetadataReader::metaCachePath($source), json_encode([
            'width' => 1234,
            'height' => 600,
            'mime' => 'image/jpeg',
            'source_format' => 'jpg',
            'failed' => false,
        ]));

        self::assertSame(1234, $this->reader->read($source)->intrinsicWidth);
    }

    public function testGoodSidecarExpiresAfterMetadataTtl(): void
    {
        rex_config::set(Config::ADDON, Config::KEY_METADATA_TTL_SECONDS, 60);
        $source = $this->source('landscape-800x600.jpg');

        $this->reader->read($source);
        $path = MetadataReader::metaCachePath($source);
        rex_file::put($path, json_encode([
            'width' => 1234,
            'height' => 600,
            'mime' => 'image/jpeg',
            'source_format' => 'jpg',
            'failed' => false,
        ]));
        touch($path, time() - 120); // age past the 60s metadata TTL

        // Expired → recompute → real intrinsic width, not the stale sentinel.
        self::assertSame(800, $this->reader->read($source)->intrinsicWidth);
    }

    public function testFailedReadWritesSentinel(): void
    {
        $source = $this->source('corrupt.jpg');

        $resolved = $this->reader->read($source);

        self::assertSame(0, $resolved->intrinsicWidth);
        self::assertSame('unknown', $resolved->sourceFormat);
        self::assertTrue($this->readSidecar($source)['failed'] ?? null, 'A failed read must persist a failed:true sentinel.');
    }

    public function testSentinelReusedWithinSentinelTtl(): void
    {
        $source = $this->source('corrupt.jpg');

        // Prime a failed sentinel carrying a marker width; within the default 60s
        // sentinel TTL it is reused without re-probing the broken asset.
        $this->reader->read($source);
        rex_file::put(MetadataReader::metaCachePath($source), json_encode([
            'width' => 4321,
            'height' => 0,
            'mime' => '',
            'source_format' => 'unknown',
            'failed' => true,
        ]));

        self::assertSame(4321, $this->reader->read($source)->intrinsicWidth);
    }

    public function testSentinelExpiresAndRetriesAfterSentinelTtl(): void
    {
        rex_config::set(Config::ADDON, Config::KEY_SENTINEL_TTL_SECONDS, 30);
        $source = $this->source('corrupt.jpg');

        $this->reader->read($source);
        $path = MetadataReader::metaCachePath($source);
        rex_file::put($path, json_encode([
            'width' => 4321,
            'height' => 0,
            'mime' => '',
            'source_format' => 'unknown',
            'failed' => true,
        ]));
        touch($path, time() - 60); // age past the 30s sentinel TTL

        // Expired sentinel → asset re-probed → still broken → width back to 0.
        self::assertSame(0, $this->reader->read($source)->intrinsicWidth);
    }

    public function testSvgIsNotTreatedAsFailed(): void
    {
        // SVG reads as 0×0 (no raster dims) but resolves to format 'svg', so it must
        // NOT be marked failed — otherwise it would inherit the short sentinel TTL
        // and be re-probed constantly. This is the key discriminator regression guard.
        $source = $this->source('vector.svg');

        $resolved = $this->reader->read($source);

        self::assertSame('svg', $resolved->sourceFormat);
        self::assertFalse($this->readSidecar($source)['failed'] ?? true, 'SVG must be a good entry, not a sentinel.');
    }
}
