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

    public function testLaterFrameClearsOpaquePixelsToTransparent(): void
    {
        @mkdir($this->tmpBase, 0777, true);
        $path = $this->tmpBase . '/transparent.gif';
        $gif = new \Imagick();
        foreach (['red', 'transparent', 'blue'] as $color) {
            $frame = new \Imagick();
            $frame->newImage(16, 16, new \ImagickPixel($color));
            $frame->setImageFormat('gif');
            $frame->setImageBackgroundColor('transparent');
            $frame->setImageDispose(\Imagick::DISPOSE_BACKGROUND);
            $frame->setImageDelay(20);
            $draw = new \ImagickDraw();
            $draw->setFillColor('red');
            $draw->rectangle(0, 0, 3, 3);
            $frame->drawImage($draw);
            $gif->addImage($frame);
            $frame->clear();
        }
        $gif->writeImages($path, true);
        $gif->clear();
        self::assertLessThan(20_000, filesize($path));
        $image = new ResolvedImage(
            new MediapoolSource('transparent.gif', $path, 1),
            16, 16, 'image/gif', 'gif', isAnimated: true, frameCount: 3,
        );

        $encoded = (new AnimatedWebpEncoder())->encode($image, 16);

        foreach ([$path, $encoded] as $file) {
            $check = new \Imagick($file);
            $canvas = $check->coalesceImages();
            $canvas->setIteratorIndex(1);
            self::assertSame(0.0, $canvas->getImagePixelColor(12, 12)->getColorValue(\Imagick::COLOR_ALPHA), $file);
            $canvas->clear();
            $check->clear();
        }
    }

    public function testEncodeNormalizesAndRestoresUmask(): void
    {
        $previous = umask(0077);
        try {
            $path = (new AnimatedWebpEncoder())->encode($this->fixture(), 64);
            self::assertFileExists($path);
            self::assertSame(0644, fileperms($path) & 0777);
            self::assertSame(0755, fileperms(dirname($path)) & 0777);
            self::assertSame(0077, umask());
        } finally {
            umask($previous);
        }
    }

    public function testGlobalLockRejectsConcurrentEncodesAndSurvivesInvalidation(): void
    {
        $lockPath = rex_path::addonData(Config::ADDON, 'animated-encode.lock');
        @mkdir(dirname($lockPath), 0777, true);
        $lock = fopen($lockPath, 'c');
        flock($lock, LOCK_EX);
        try {
            $encoder = new AnimatedWebpEncoder();
            self::assertSame(AnimatedWebpEncoder::BUSY, $encoder->encode($this->fixture(), 64));
            self::assertSame(AnimatedWebpEncoder::BUSY, $encoder->encode($this->fixture('webp'), 320));
            \Ynamite\Media\Pipeline\CacheInvalidator::invalidate('animated-3frame.gif');
            self::assertFileExists($lockPath);
            self::assertSame(AnimatedWebpEncoder::BUSY, $encoder->encode($this->fixture(), 64));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        self::assertFileExists($encoder->encode($this->fixture(), 64));
    }

    public function testWaitingEncoderRechecksCacheAfterGlobalLock(): void
    {
        $image = $this->fixture();
        $cachePath = AnimatedWebpEncoder::cacheFile($image->source->key(), 64, 64);
        $lockPath = rex_path::addonData(Config::ADDON, 'animated-encode.lock');
        @mkdir(dirname($lockPath), 0777, true);
        $writer = <<<'PHP'
            $lock = fopen($argv[1], 'c');
            flock($lock, LOCK_EX);
            fwrite(STDOUT, "locked\n");
            fgets(STDIN);
            usleep(100000);
            mkdir(dirname($argv[2]), 0755, true);
            file_put_contents($argv[2], 'winner-cache');
            flock($lock, LOCK_UN);
            fclose($lock);
            PHP;
        $process = proc_open([PHP_BINARY, '-r', $writer, $lockPath, $cachePath], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        try {
            self::assertSame("locked\n", fgets($pipes[1]));
            fwrite($pipes[0], "start\n");

            self::assertSame($cachePath, (new AnimatedWebpEncoder())->encode($image, 64, wait: true));
            self::assertSame('winner-cache', file_get_contents($cachePath), 'Waiting caller must not decode and overwrite the winner');
        } finally {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            self::assertSame(0, proc_close($process));
        }
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

        \rex_logger::_reset();
        (new AnimatedWebpEncoder())->prewarm($image);

        self::assertNotEmpty(\rex_logger::$logged, 'failure must be logged, not thrown');
        self::assertSame(
            [],
            glob(rex_path::addonAssets(Config::ADDON, 'cache/corrupt.gif/animated*.webp')) ?: [],
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
