<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Pipeline;

use PHPUnit\Framework\TestCase;
use rex_config;
use rex_logger;
use rex_path;
use Ynamite\Media\Config;
use Ynamite\Media\Pipeline\AnimatedWebpEncoder;
use Ynamite\Media\Pipeline\ResolvedImage;
use Ynamite\Media\Source\MediapoolSource;
use Ynamite\Media\Source\SourceInterface;

/**
 * Width selection, cache naming and cheap rejection/cache-hit branches.
 */
final class AnimatedWebpEncoderTest extends TestCase
{
    private string $tmpBase;

    protected function setUp(): void
    {
        $this->tmpBase = sys_get_temp_dir() . '/massif_media_anim_' . uniqid('', true);
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

    private function image(string $format, bool $animated, int $width = 100): ResolvedImage
    {
        return new ResolvedImage(
            source: new MediapoolSource(
                filename: 'src.' . $format,
                absolutePath: $this->tmpBase . '/media/src.' . $format,
                mtime: 1_700_000_000,
            ),
            intrinsicWidth: $width,
            intrinsicHeight: 100,
            mime: 'image/' . $format,
            sourceFormat: $format,
            isAnimated: $animated,
            frameCount: $animated ? 3 : 1,
        );
    }

    public function testStaticGifIsRejected(): void
    {
        self::assertSame('', (new AnimatedWebpEncoder())->encode($this->image('gif', false), 100));
    }

    public function testAnimatedUnsupportedFormatIsRejected(): void
    {
        self::assertSame('', (new AnimatedWebpEncoder())->encode($this->image('png', true), 100));
    }

    public function testReturnsCachedPathWhenCacheFileAlreadyPresent(): void
    {
        $image = $this->image('gif', true);
        $cachePath = AnimatedWebpEncoder::cacheFile($image->source->key(), 100, 100);
        @mkdir(dirname($cachePath), 0777, true);
        file_put_contents($cachePath, "fake animated webp bytes (>0 length)");

        $result = (new AnimatedWebpEncoder())->encode($image, 100);

        self::assertSame($cachePath, $result);
    }

    public function testWidthsForLargeSource(): void
    {
        self::assertSame([640, 1280, 1600], AnimatedWebpEncoder::widths($this->image('gif', true, 1600)));
    }

    public function testWidthsForMidSource(): void
    {
        self::assertSame([640, 1000], AnimatedWebpEncoder::widths($this->image('gif', true, 1000)));
    }

    public function testWidthsForSmallSource(): void
    {
        self::assertSame([317], AnimatedWebpEncoder::widths($this->image('gif', true, 317)));
    }

    public function testWidthsAtBreakpointsAreNotDuplicated(): void
    {
        self::assertSame([640], AnimatedWebpEncoder::widths($this->image('gif', true, 640)));
        self::assertSame([640, 1280], AnimatedWebpEncoder::widths($this->image('gif', true, 1280)));
    }

    public function testLargestWidthKeepsLegacyName(): void
    {
        self::assertSame('a.gif/animated.webp', AnimatedWebpEncoder::cacheRelPath('a.gif', 1600, 1600));
        self::assertSame('subdir/anim.gif/animated.webp', AnimatedWebpEncoder::cacheRelPath('subdir/anim.gif', 317, 317));
    }

    public function testSmallerWidthIsSuffixed(): void
    {
        self::assertSame('a.gif/animated-640.webp', AnimatedWebpEncoder::cacheRelPath('a.gif', 640, 1600));
    }

    public function testOverBudgetIsRejectedEvenWhenCached(): void
    {
        $image = $this->image('gif', true);
        $cachePath = AnimatedWebpEncoder::cacheFile($image->source->key(), 100, 100);
        @mkdir(dirname($cachePath), 0777, true);
        file_put_contents($cachePath, 'cached webp');
        rex_config::set(Config::ADDON, Config::KEY_ANIMATED_MAX_MPX, 0);

        self::assertSame('', (new AnimatedWebpEncoder())->encode($image, 100));
    }

    public function testPrewarmSwallowsAndLogsThrowable(): void
    {
        $failure = new \Error('Source unavailable');
        $source = $this->createStub(SourceInterface::class);
        $source->method('isExternal')->willReturn(false);
        $source->method('key')->willThrowException($failure);
        $image = new ResolvedImage($source, 100, 100, 'image/gif', 'gif', isAnimated: true, frameCount: 3);

        (new AnimatedWebpEncoder())->prewarm($image);

        self::assertSame([$failure], rex_logger::$logged);
    }
}
