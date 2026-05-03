<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Pipeline;

use PHPUnit\Framework\TestCase;
use rex_path;
use Ynamite\Media\Config;
use Ynamite\Media\Pipeline\AnimatedWebpEncoder;
use Ynamite\Media\Pipeline\ResolvedImage;

/**
 * Cheap-branch tests for AnimatedWebpEncoder: the gate (must be animated GIF)
 * and the cache-hit short-circuit. The Imagick path itself is exercised in
 * the Integration suite against a real animated GIF fixture.
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

    private function image(string $format, bool $animated): ResolvedImage
    {
        return new ResolvedImage(
            sourcePath: 'src.' . $format,
            absolutePath: $this->tmpBase . '/media/src.' . $format,
            intrinsicWidth: 100,
            intrinsicHeight: 100,
            mime: 'image/' . $format,
            sourceFormat: $format,
            mtime: 1_700_000_000,
            isAnimated: $animated,
        );
    }

    public function testStaticGifIsRejected(): void
    {
        self::assertSame('', (new AnimatedWebpEncoder())->encode($this->image('gif', false)));
    }

    public function testAnimatedNonGifIsRejected(): void
    {
        // v1 MVP only handles animated GIF. Animated PNG/WebP-as-source aren't
        // in scope yet — should silently bail without writing anything.
        self::assertSame('', (new AnimatedWebpEncoder())->encode($this->image('png', true)));
        self::assertSame('', (new AnimatedWebpEncoder())->encode($this->image('webp', true)));
    }

    public function testReturnsCachedPathWhenCacheFileAlreadyPresent(): void
    {
        $image = $this->image('gif', true);
        $cachePath = AnimatedWebpEncoder::cacheFile($image->sourcePath);
        @mkdir(dirname($cachePath), 0777, true);
        file_put_contents($cachePath, "fake animated webp bytes (>0 length)");

        $result = (new AnimatedWebpEncoder())->encode($image);

        self::assertSame($cachePath, $result);
    }

    public function testCacheRelPathShape(): void
    {
        // Locks the URL/cache contract — Endpoint matches str_ends_with on
        // '/animated.webp' and parseCachePath skips animated paths.
        self::assertSame('foo.gif/animated.webp', AnimatedWebpEncoder::cacheRelPath('foo.gif'));
        self::assertSame('subdir/anim.gif/animated.webp', AnimatedWebpEncoder::cacheRelPath('subdir/anim.gif'));
    }
}
