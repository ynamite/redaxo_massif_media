<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Integration;

use PHPUnit\Framework\TestCase;
use Ynamite\Media\Builder\ImageBuilder;
use Ynamite\Media\Config;
use Ynamite\Media\Image;
use Ynamite\Media\Pic;

/**
 * `Pic` is the short alias `final class Pic extends Image {}`. These guard that
 * it stays a transparent, end-to-end-identical drop-in for `Image` — if someone
 * adds state or overrides a method on either class, the equivalence breaks here.
 */
final class PicAliasTest extends TestCase
{
    private string $tmpDir;
    private string $mediaDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/massif_media_pic_' . uniqid('', true);
        $this->mediaDir = $this->tmpDir . '/media';
        @mkdir($this->mediaDir, 0777, true);
        \rex_path::_setBase($this->tmpDir);

        \rex_config::_reset();
        \rex_config::set(Config::ADDON, Config::KEY_SIGN_KEY, 'integration-test-key');
        \rex_config::set(Config::ADDON, Config::KEY_DEVICE_SIZES, '640,750,828,1080,1200,1920,2048,3840');
        \rex_config::set(Config::ADDON, Config::KEY_IMAGE_SIZES, '16,32,48,64,96,128,256,384');

        copy(__DIR__ . '/../_fixtures/landscape-800x600.jpg', $this->mediaDir . '/hero.jpg');
    }

    protected function tearDown(): void
    {
        \rex_dir::delete($this->tmpDir, true);
        \rex_config::_reset();
    }

    public function testPicIsSubclassOfImage(): void
    {
        self::assertTrue(is_subclass_of(Pic::class, Image::class));
    }

    public function testPicForReturnsImageBuilder(): void
    {
        self::assertInstanceOf(ImageBuilder::class, Pic::for('hero.jpg'));
    }

    public function testPicUrlIsIdenticalToImageUrl(): void
    {
        self::assertSame(
            Image::url('hero.jpg', width: 400, format: 'webp', quality: 75),
            Pic::url('hero.jpg', width: 400, format: 'webp', quality: 75),
        );
    }

    public function testPicPictureIsIdenticalToImagePicture(): void
    {
        self::assertSame(
            Image::picture(src: 'hero.jpg', alt: 'Aussicht', width: 400),
            Pic::picture(src: 'hero.jpg', alt: 'Aussicht', width: 400),
        );
    }
}
