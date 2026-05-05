<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Integration;

use PHPUnit\Framework\TestCase;
use rex_path;
use Ynamite\Media\Pipeline\AnimatedWebpEncoder;
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
    private string $tmpBase;
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

    private function fixture(): ResolvedImage
    {
        return new ResolvedImage(
            source: new MediapoolSource(
                filename: 'animated-3frame.gif',
                absolutePath: $this->fixturesDir . '/animated-3frame.gif',
                mtime: 1_700_000_000,
            ),
            intrinsicWidth: 64,
            intrinsicHeight: 64,
            mime: 'image/gif',
            sourceFormat: 'gif',
            isAnimated: true,
        );
    }

    public function testEncodesAnimatedGifToMultiFrameWebp(): void
    {
        $absPath = (new AnimatedWebpEncoder())->encode($this->fixture());

        self::assertNotSame('', $absPath, 'Encoder should return the cache path on success.');
        self::assertFileExists($absPath);
        self::assertStringEndsWith('animated.webp', $absPath);

        $check = new \Imagick($absPath);
        self::assertGreaterThan(1, $check->getNumberImages(), 'Output WebP should preserve all source frames.');
        self::assertSame('WEBP', strtoupper((string) $check->getImageFormat()));
        $check->clear();
    }

    public function testCacheHitOnSecondCall(): void
    {
        $encoder = new AnimatedWebpEncoder();
        $first = $encoder->encode($this->fixture());
        clearstatcache();
        $mtime1 = filemtime($first);

        usleep(10_000);
        $second = $encoder->encode($this->fixture());
        clearstatcache();
        $mtime2 = filemtime($second);

        self::assertSame($first, $second);
        self::assertSame($mtime1, $mtime2, 'Second call should be a cache hit, not a rewrite.');
    }
}
