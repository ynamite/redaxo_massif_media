<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Massif\Media\Helpers\Sources;
use Ynamite\Media\Config;
use Ynamite\Media\Image;
use Ynamite\Media\Pipeline\ResolvedImage;
use Ynamite\Media\Pipeline\UrlBuilder;
use Ynamite\Media\Source\ExternalSource;
use Ynamite\Media\Video;
use Ynamite\Media\View\PassthroughRenderer;

final class AssetHostTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/massif_asset_host_' . uniqid('', true);
        mkdir($this->tmpDir . '/media', 0777, true);
        \rex_path::_setBase($this->tmpDir);
        \rex_config::_reset();
        \rex_config::set(Config::ADDON, Config::KEY_SIGN_KEY, 'asset-host-test');
        file_put_contents($this->tmpDir . '/media/clip.mp4', '');
    }

    protected function tearDown(): void
    {
        \rex_dir::delete($this->tmpDir, true);
        \rex_config::_reset();
    }

    public function testEmptyHostKeepsVariantUrlUnchanged(): void
    {
        $builder = new UrlBuilder();
        $url = $builder->build(Sources::image(), 400, 'webp');
        \rex_config::set(Config::ADDON, 'asset_host', '');

        self::assertSame($url, $builder->build(Sources::image(), 400, 'webp'));
        self::assertStringStartsWith('/assets/addons/massif_media/cache/', $url);
        self::assertStringContainsString('src="/media/clip.mp4?v=', Video::render('clip.mp4'));
    }

    public function testHostPrefixesVariantWithoutChangingSignedPathOrQuery(): void
    {
        $builder = new UrlBuilder();
        $url = $builder->build(Sources::image(), 400, 'webp', filterParams: ['bri' => 10]);
        \rex_config::set(Config::ADDON, 'asset_host', 'https://cdn.example.net');
        $hosted = $builder->build(Sources::image(), 400, 'webp', filterParams: ['bri' => 10]);

        self::assertStringStartsWith('https://cdn.example.net/assets/addons/massif_media/cache/', $hosted);
        self::assertSame('https://cdn.example.net' . $url, $hosted);
    }

    public function testHostPrefixesVideoSource(): void
    {
        \rex_config::set(Config::ADDON, 'asset_host', 'https://cdn.example.net');

        self::assertStringContainsString('src="https://cdn.example.net/media/clip.mp4?v=', Video::render('clip.mp4'));
    }

    public function testHostIsEmptyByDefaultAndHasNoTrailingSlash(): void
    {
        self::assertSame('', Config::assetHost());
        \rex_config::set(Config::ADDON, 'asset_host', 'https://cdn.example.net///');

        self::assertSame('https://cdn.example.net', Config::assetHost());
        self::assertStringContainsString('src="https://cdn.example.net/media/', Video::render('clip.mp4'));
    }

    public function testHostPrefixesAnimatedWebp(): void
    {
        \rex_config::set(Config::ADDON, 'asset_host', 'https://cdn.example.net');
        $image = Sources::image('banner.gif', sourceFormat: 'gif', isAnimated: true);

        self::assertStringStartsWith(
            'https://cdn.example.net/assets/addons/massif_media/cache/banner.gif/animated.webp?s=',
            (new UrlBuilder())->buildAnimatedWebp($image),
        );
    }

    public function testHostPrefixesSvgAndGifSingleUrlsAndMarkup(): void
    {
        \rex_config::set(Config::ADDON, 'asset_host', 'https://cdn.example.net');
        foreach (['vector.svg', 'tiny-32x32.gif'] as $fixture) {
            copy(__DIR__ . '/../_fixtures/' . $fixture, $this->tmpDir . '/media/' . $fixture);
            $url = 'https://cdn.example.net/media/' . $fixture;
            self::assertSame($url, Image::url($fixture));
            self::assertStringContainsString('src="' . $url . '"', Image::picture($fixture));
        }
    }

    public function testCdnModeKeepsItsExistingImageVideoAndPassthroughUrls(): void
    {
        \rex_config::set(Config::ADDON, 'asset_host', 'https://cdn.example.net');
        \rex_config::set(Config::ADDON, Config::KEY_CDN_ENABLED, '|1|');
        \rex_config::set(Config::ADDON, Config::KEY_CDN_BASE, 'https://optimizer.example.com');
        \rex_config::set(Config::ADDON, Config::KEY_CDN_URL_TEMPLATE, '{src}?w={w}&fm={fm}&q={q}');

        self::assertSame('https://optimizer.example.com/hero.jpg?w=400&fm=webp&q=75', (new UrlBuilder())->build(Sources::image(), 400, 'webp', 75));
        self::assertStringContainsString('src="https://optimizer.example.com/clip.mp4?v=', Video::render('clip.mp4'));
        $gif = Sources::image('banner.gif', sourceFormat: 'gif', isAnimated: true);
        self::assertSame('', (new UrlBuilder())->buildAnimatedWebp($gif));
        self::assertStringContainsString('src="/media/banner.gif"', (new PassthroughRenderer())->render($gif));
    }

    public function testAssetHostHandlesRelativeAndSubdirectoryPaths(): void
    {
        self::assertSame('../media/clip.mp4', UrlBuilder::assetUrl('../media/clip.mp4'));
        \rex_config::set(Config::ADDON, 'asset_host', 'https://cdn.example.net/');
        self::assertSame('https://cdn.example.net/media/clip.mp4', UrlBuilder::assetUrl('../media/clip.mp4'));
        self::assertSame('https://cdn.example.net/site/media/clip.mp4', UrlBuilder::assetUrl('/site/media/clip.mp4'));
    }

    public function testExternalSourcesStayUnchanged(): void
    {
        $source = new ExternalSource('https://origin.example.com/photo.jpg', 'abc123', '/tmp/origin.bin', 123, null, null, 3600);
        $image = new ResolvedImage($source, 800, 600, 'image/jpeg', 'jpg');
        $builder = new UrlBuilder();
        $url = $builder->build($image, 400, 'webp');
        \rex_config::set(Config::ADDON, 'asset_host', 'https://cdn.example.net');

        self::assertSame($url, $builder->build($image, 400, 'webp'));
        self::assertStringContainsString('src="https://origin.example.com/photo.jpg"', (new PassthroughRenderer())->render($image));
        self::assertSame('', $builder->buildAnimatedWebp($image));
        self::assertStringContainsString('src="https://origin.example.com/clip.mp4"', Video::render('https://origin.example.com/clip.mp4'));
    }
}
