<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Integration;

use PHPUnit\Framework\TestCase;
use rex_config;
use rex_dir;
use rex_path;
use Ynamite\Media\Config;
use Ynamite\Media\Pipeline\Placeholder;
use Ynamite\Media\Pipeline\RenderBudget;
use Ynamite\Media\Pipeline\ResolvedImage;
use Ynamite\Media\Source\MediapoolSource;

/**
 * Real Glide encode of the LQIP. Needs Imagick for the transparent fixture.
 */
final class PlaceholderTest extends TestCase
{
    private string $tmpBase;

    protected function setUp(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('Fixture generation requires Imagick.');
        }
        $this->tmpBase = sys_get_temp_dir() . '/massif_media_lqip_int_' . uniqid('', true);
        rex_path::_setBase($this->tmpBase);
        @mkdir(rex_path::media(), 0777, true);
        rex_config::set(Config::ADDON, Config::KEY_LQIP_ENABLED, 1);
        rex_config::set(Config::ADDON, 'sign_key', 'integration-test-key');
    }

    protected function tearDown(): void
    {
        rex_config::_reset();
        rex_dir::delete($this->tmpBase, true);
    }

    public function testOpaqueImageGetsLqip(): void
    {
        copy(__DIR__ . '/../_fixtures/landscape-800x600.jpg', rex_path::media('hero.jpg'));

        $lqip = (new Placeholder())->generate($this->image('hero.jpg', 'jpg', 800, 600));

        self::assertStringStartsWith('data:image/webp;base64,', $lqip);
    }

    public function testTransparentImageGetsNoLqipAndIsNotEncodedAgain(): void
    {
        // The LQIP is a background on the <img> and stays there after load:
        // behind a transparent logo it shows as a blurred halo.
        $im = new \Imagick();
        $im->newImage(400, 400, new \ImagickPixel('transparent'));
        $draw = new \ImagickDraw();
        $draw->setFillColor(new \ImagickPixel('#000000'));
        $draw->circle(200, 200, 200, 80);
        $im->drawImage($draw);
        $im->setImageFormat('png');
        $im->writeImage(rex_path::media('logo.png'));
        $im->clear();
        $image = $this->image('logo.png', 'png', 400, 400);

        self::assertSame('', (new Placeholder())->generate($image));
        // Remembered with a value that is harmless as a URL.
        self::assertSame('data:,', file_get_contents(Placeholder::cachePathFor($image->source)));
        RenderBudget::spend(RenderBudget::BUDGET_MS);
        self::assertSame('', (new Placeholder())->generate($image));
    }

    private function image(string $filename, string $format, int $w, int $h): ResolvedImage
    {
        return new ResolvedImage(
            source: new MediapoolSource(
                filename: $filename,
                absolutePath: rex_path::media($filename),
                mtime: 1_700_000_000,
            ),
            intrinsicWidth: $w,
            intrinsicHeight: $h,
            mime: 'image/' . $format,
            sourceFormat: $format,
        );
    }
}
