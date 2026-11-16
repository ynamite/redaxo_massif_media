<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Pipeline;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use rex_config;
use Tests\Massif\Media\Helpers\Sources;
use Ynamite\Media\Config;
use Ynamite\Media\Pipeline\ResolvedImage;
use Ynamite\Media\Source\ExternalSource;

final class ResolvedImageTest extends TestCase
{
    protected function tearDown(): void
    {
        rex_config::_reset();
    }

    public function testAnimatedWebpIsPassthrough(): void
    {
        self::assertTrue(Sources::image(sourceFormat: 'webp', isAnimated: true)->isPassthrough());
        self::assertFalse(Sources::image(sourceFormat: 'webp')->isPassthrough());
        self::assertTrue(Sources::image(sourceFormat: 'gif')->isPassthrough());
        self::assertTrue(Sources::image(sourceFormat: 'svg')->isPassthrough());
    }

    public function testWithinBudgetIsEncodable(): void
    {
        foreach (['gif', 'webp'] as $format) {
            $image = new ResolvedImage(Sources::mediapool(), 64, 64, 'image/' . $format, $format, isAnimated: true, frameCount: 3);

            self::assertTrue($image->isAnimatedEncodable());
        }
    }

    public function testOverBudgetNotEncodable(): void
    {
        rex_config::set(Config::ADDON, Config::KEY_ANIMATED_MAX_MPX, 40);
        $image = new ResolvedImage(Sources::mediapool(), 1600, 1000, 'image/gif', 'gif', isAnimated: true, frameCount: 342);

        self::assertFalse($image->isAnimatedEncodable());
    }

    public function testExactBudgetIsEncodable(): void
    {
        rex_config::set(Config::ADDON, Config::KEY_ANIMATED_MAX_MPX, 2);
        $image = new ResolvedImage(Sources::mediapool(), 1000, 1000, 'image/gif', 'gif', isAnimated: true, frameCount: 2);

        self::assertTrue($image->isAnimatedEncodable());
    }

    #[DataProvider('unreadableDimensions')]
    public function testZeroDimensionsNotEncodable(int $width, int $height, int $frames): void
    {
        $image = new ResolvedImage(Sources::mediapool(), $width, $height, 'image/gif', 'gif', isAnimated: true, frameCount: $frames);

        self::assertFalse($image->isAnimatedEncodable());
    }

    public static function unreadableDimensions(): array
    {
        return [
            'zero dimensions' => [0, 0, 3],
            'zero width' => [0, 64, 3],
            'zero height' => [64, 0, 3],
            'zero frames' => [64, 64, 0],
        ];
    }

    public function testZeroBudgetDisablesEncoding(): void
    {
        rex_config::set(Config::ADDON, Config::KEY_ANIMATED_MAX_MPX, 0);
        $image = new ResolvedImage(Sources::mediapool(), 64, 64, 'image/gif', 'gif', isAnimated: true, frameCount: 3);

        self::assertFalse($image->isAnimatedEncodable());
    }

    public function testExternalNotEncodable(): void
    {
        $source = new ExternalSource('https://example.com/image.gif', 'abc123', '/tmp/image.gif', 1, null, null, 60);
        $image = new ResolvedImage($source, 64, 64, 'image/gif', 'gif', isAnimated: true, frameCount: 3);

        self::assertFalse($image->isAnimatedEncodable());
    }

    public function testStaticAndUnsupportedFormatsNotEncodable(): void
    {
        self::assertFalse(Sources::image(sourceFormat: 'gif')->isAnimatedEncodable());
        self::assertFalse(Sources::image(sourceFormat: 'png', isAnimated: true)->isAnimatedEncodable());
        self::assertFalse(Sources::image(sourceFormat: 'svg', isAnimated: true)->isAnimatedEncodable());
    }

    public function testWithFocalKeepsFrameCount(): void
    {
        $image = new ResolvedImage(Sources::mediapool(), 64, 64, 'image/gif', 'gif', isAnimated: true, frameCount: 3);

        $focused = $image->withFocal('25% 75%');

        self::assertSame(3, $focused->frameCount);
        self::assertTrue($focused->isAnimated);
        self::assertSame('25% 75%', $focused->focalPoint);
        self::assertTrue($focused->isAnimatedEncodable());
    }
}
