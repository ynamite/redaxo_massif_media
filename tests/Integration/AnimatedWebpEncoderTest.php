<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Integration;

use PHPUnit\Framework\TestCase;
use rex_config;
use rex_logger;
use rex_path;
use Ynamite\Media\Config;
use Ynamite\Media\Pipeline\AnimatedWebpEncoder;
use Ynamite\Media\Pipeline\CacheStats;
use Ynamite\Media\Pipeline\ResolvedImage;
use Ynamite\Media\Source\MediapoolSource;

/**
 * Real Imagick path: feed the encoder an animated GIF fixture, assert it
 * produces a multi-frame WebP at the canonical cache location and that a
 * second call hits the cache (no rewrite). Skipped when Imagick or its WebP
 * delegate is missing.
 */
final class AnimatedWebpEncoderTest extends TestCase
{
    private string $tmpBase = '';
    private string $fixturesDir;

    protected function setUp(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('Animated WebP encoding needs Imagick.');
        }
        if (!in_array('WEBP', (new \Imagick())->queryFormats('WEBP'), true)) {
            $this->markTestSkipped('Imagick build lacks WebP encode delegate.');
        }
        $this->tmpBase = sys_get_temp_dir() . '/massif_anim_int_' . uniqid('', true);
        $this->fixturesDir = __DIR__ . '/../_fixtures';
        rex_path::_setBase($this->tmpBase);
    }

    protected function tearDown(): void
    {
        rex_config::_reset();
        rex_logger::_reset();
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

    private function fixture(string $format = 'gif'): ResolvedImage
    {
        return new ResolvedImage(
            source: new MediapoolSource(
                filename: 'animated-3frame.' . $format,
                absolutePath: $this->fixturesDir . '/animated-3frame.' . $format,
                mtime: 1_700_000_000,
            ),
            intrinsicWidth: $format === 'webp' ? 320 : 64,
            intrinsicHeight: $format === 'webp' ? 200 : 64,
            mime: 'image/' . $format,
            sourceFormat: $format,
            isAnimated: true,
            frameCount: 3,
        );
    }

    private function largeGif(): ResolvedImage
    {
        @mkdir($this->tmpBase, 0777, true);
        $path = $this->tmpBase . '/large.gif';
        $gif = new \Imagick();
        foreach (['red', 'green', 'blue'] as $color) {
            $frame = new \Imagick();
            $frame->newImage(1400, 200, new \ImagickPixel($color));
            $frame->setImageFormat('gif');
            $frame->setImageDelay(20);
            $gif->addImage($frame);
            $frame->clear();
        }
        $gif->writeImages($path, true);
        $gif->clear();

        return new ResolvedImage(
            new MediapoolSource('large.gif', $path, 1_700_000_000),
            1400, 200, 'image/gif', 'gif', isAnimated: true, frameCount: 3,
        );
    }

    public function testEncodesAnimatedGifToMultiFrameWebp(): void
    {
        $absPath = (new AnimatedWebpEncoder())->encode($this->fixture(), 64);

        self::assertNotSame('', $absPath, 'Encoder should return the cache path on success.');
        self::assertFileExists($absPath);
        self::assertStringEndsWith('animated.webp', $absPath);

        $check = new \Imagick($absPath);
        self::assertSame(3, $check->getNumberImages(), 'Output WebP should preserve all source frames.');
        self::assertSame('WEBP', strtoupper((string) $check->getImageFormat()));
        $check->clear();
    }

    public function testEncodesGifAtWidth(): void
    {
        $path = (new AnimatedWebpEncoder())->encode($this->largeGif(), 640);

        self::assertFileExists($path);
        self::assertStringEndsWith('large.gif/animated-640.webp', $path);
        $check = new \Imagick($path);
        self::assertSame(3, $check->getNumberImages());
        $canvas = $check->coalesceImages();
        foreach ($canvas as $frame) {
            self::assertSame(640, $frame->getImageWidth());
            self::assertSame(91, $frame->getImageHeight());
            self::assertSame(20, $frame->getImageDelay());
        }
        $canvas->clear();
        $check->clear();
    }

    public function testResizesAnimatedWebpSource(): void
    {
        $image = new ResolvedImage(
            new MediapoolSource('animated-700.webp', $this->fixturesDir . '/animated-700.webp', 1_700_000_000),
            700, 300, 'image/webp', 'webp', isAnimated: true, frameCount: 3,
        );
        $path = (new AnimatedWebpEncoder())->encode($image, 640);

        self::assertFileExists($path);
        $check = new \Imagick($path);
        self::assertSame(3, $check->getNumberImages());
        self::assertSame(640, $check->getImageWidth());
        $check->clear();
    }

    public function testFailedWriteNeverPublishesAndLeavesNoTempFiles(): void
    {
        $image = $this->fixture();
        $final = AnimatedWebpEncoder::cacheFile('animated-3frame.gif', 64, 64);
        mkdir($final, 0777, true); // rename onto a directory fails

        self::assertSame('', (new AnimatedWebpEncoder())->encode($image, 64));
        self::assertDirectoryExists($final);
        self::assertSame(['.', '..'], scandir($final));
        self::assertSame(['.', '..', 'animated.webp'], scandir(dirname($final)));
    }

    public function testSuccessLeavesNoTempFiles(): void
    {
        $path = (new AnimatedWebpEncoder())->encode($this->fixture(), 64);

        self::assertSame(['.', '..', 'animated.webp'], scandir(dirname($path)));
    }

    public function testEncodesAnimatedWebpSource(): void
    {
        $image = $this->fixture('webp');
        $path = (new AnimatedWebpEncoder())->encode($image, $image->intrinsicWidth);

        self::assertFileExists($path);
        $check = new \Imagick($path);
        self::assertSame(3, $check->getNumberImages());
        self::assertSame(320, $check->getImageWidth());
        self::assertSame(200, $check->getImageHeight());
        $check->clear();
    }

    public function testRefusesUnknownWidth(): void
    {
        $image = $this->largeGif();

        self::assertSame('', (new AnimatedWebpEncoder())->encode($image, 777));
        self::assertDirectoryDoesNotExist(rex_path::addonAssets(Config::ADDON, 'cache/large.gif'));
    }

    public function testRefusesOverBudget(): void
    {
        rex_config::set(Config::ADDON, Config::KEY_ANIMATED_MAX_MPX, 0);

        self::assertSame('', (new AnimatedWebpEncoder())->encode($this->fixture(), 64));
        self::assertFileDoesNotExist(rex_path::addonAssets(Config::ADDON, 'cache/animated-3frame.gif/animated.webp'));
    }

    public function testPrewarmEncodesEveryWidth(): void
    {
        (new AnimatedWebpEncoder())->prewarm($this->largeGif());

        $dir = rex_path::addonAssets(Config::ADDON, 'cache/large.gif/');
        foreach (['animated-640.webp' => 640, 'animated-1280.webp' => 1280, 'animated.webp' => 1400] as $name => $width) {
            self::assertFileExists($dir . $name);
            $check = new \Imagick($dir . $name);
            self::assertSame(3, $check->getNumberImages());
            self::assertSame($width, $check->getImageWidth());
            $check->clear();
        }
        self::assertSame(3, (new CacheStats())->compute()['by_kind']['animated']['count']);
    }

    public function testPrewarmSwallowsFailures(): void
    {
        @mkdir($this->tmpBase, 0777, true);
        $path = $this->tmpBase . '/corrupt.gif';
        copy(__DIR__ . '/../_fixtures/corrupt.jpg', $path);
        $image = new ResolvedImage(
            new MediapoolSource('corrupt.gif', $path, 1_700_000_000),
            1400, 200, 'image/gif', 'gif', isAnimated: true, frameCount: 2,
        );

        (new AnimatedWebpEncoder())->prewarm($image);

        self::assertFileDoesNotExist(
            rex_path::addonAssets(Config::ADDON, 'cache/corrupt.gif/animated.webp'),
        );
    }

    public function testSecondCallHitsCache(): void
    {
        $encoder = new AnimatedWebpEncoder();
        $image = $this->largeGif();
        $first = $encoder->encode($image, 640);
        self::assertFileExists($first);
        touch($first, time() - 60);
        clearstatcache();
        $mtime1 = filemtime($first);

        $second = $encoder->encode($image, 640);
        clearstatcache();
        $mtime2 = filemtime($second);

        self::assertSame($first, $second);
        self::assertSame($mtime1, $mtime2, 'Second call should be a cache hit, not a rewrite.');
    }
}
