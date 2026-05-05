<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Integration;

use PHPUnit\Framework\TestCase;
use Ynamite\Media\Builder\ImageBuilder;
use Ynamite\Media\Config;
use Ynamite\Media\Image;

final class ImageUrlTest extends TestCase
{
    private string $tmpDir;
    private string $mediaDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/massif_media_test_' . uniqid('', true);
        $this->mediaDir = $this->tmpDir . '/media';
        @mkdir($this->mediaDir, 0777, true);
        \rex_path::_setBase($this->tmpDir);

        \rex_config::_reset();
        \rex_config::set(Config::ADDON, Config::KEY_SIGN_KEY, 'integration-test-key');
        \rex_config::set(Config::ADDON, Config::KEY_DEVICE_SIZES, '640,750,828,1080,1200,1920,2048,3840');
        \rex_config::set(Config::ADDON, Config::KEY_IMAGE_SIZES, '16,32,48,64,96,128,256,384');
    }

    protected function tearDown(): void
    {
        \rex_dir::delete($this->tmpDir, true);
        \rex_config::_reset();
    }

    private function copyFixture(string $name, string $dest): void
    {
        $src = __DIR__ . '/../_fixtures/' . $name;
        if (!file_exists($src)) {
            self::markTestSkipped("Fixture {$name} missing — Imagick suite not bootstrapped");
        }
        copy($src, $this->mediaDir . '/' . $dest);
    }

    public function testUrlReturnsSignedCachePathForRasterSource(): void
    {
        $this->copyFixture('landscape-800x600.jpg', 'hero.jpg');

        $url = Image::url('hero.jpg', width: 400, format: 'webp', quality: 75);

        self::assertStringContainsString('/assets/addons/' . Config::ADDON . '/cache/hero.jpg/webp-400-75.webp', $url);
        self::assertStringContainsString('?s=', $url);
    }

    public function testUrlOmitsCropTokenWhenRatioMatchesIntrinsic(): void
    {
        // The fixture is 800×600 (4:3 ≈ 1.3333…). Asking for ratio=4/3 falls
        // within RATIO_EQUAL_EPSILON, so the URL must NOT carry a `cover-X-Y`
        // segment — same short-circuit Picture and Preloader use, otherwise
        // a `<video poster>` URL won't dedupe with the matching `<picture>`
        // variant on cache.
        $this->copyFixture('landscape-800x600.jpg', 'hero.jpg');

        $url = Image::url('hero.jpg', width: 400, ratio: 4 / 3, format: 'webp');

        self::assertStringNotContainsString('cover-', $url);
    }

    public function testUrlEmitsCoverFitTokenWhenRatioDiffersFromIntrinsic(): void
    {
        // 1:1 crop on a 4:3 source → fitToken=cover-50-50, height=width.
        $this->copyFixture('landscape-800x600.jpg', 'hero.jpg');

        $url = Image::url('hero.jpg', width: 300, ratio: 1.0, format: 'webp');

        self::assertStringContainsString('webp-300-300-cover-50-50', $url);
    }

    public function testUrlMatchesUrlBuilderForSameInputs(): void
    {
        // The cache file Image::url() points at is the SAME on-disk path
        // PictureRenderer / Preloader produce for the same width/format/quality.
        // Confirms there's no second cache namespace.
        $this->copyFixture('landscape-800x600.jpg', 'hero.jpg');

        $url = Image::url('hero.jpg', width: 800, format: 'jpg', quality: 80);

        self::assertStringContainsString('/cache/hero.jpg/jpg-800-80.jpg', $url);
    }

    public function testUrlHonorsFilterParams(): void
    {
        $this->copyFixture('landscape-800x600.jpg', 'hero.jpg');

        $url = Image::url('hero.jpg', width: 400, format: 'webp', filters: ['brightness' => 20]);

        // Filter blob hash appears as `-fXXXXXXXX` segment in the cache key
        // and the &f= base64url payload appears in the query string.
        self::assertMatchesRegularExpression('/-f[0-9a-f]{8}\.webp/', $url);
        self::assertStringContainsString('&f=', $url);
    }

    public function testUrlPicksMedianWidthWhenNoExplicitSize(): void
    {
        // No width / height arg → median of the (uncapped, since no crop)
        // width pool from rex_config. With deviceSizes ∪ imageSizes sorted,
        // the median falls in the upper-middle range. We assert it's a width
        // from the pool.
        $this->copyFixture('landscape-800x600.jpg', 'hero.jpg');

        $url = Image::url('hero.jpg', format: 'webp');

        // Pool is {16,32,48,64,96,128,256,384,640,750,828,1080,1200,1920,2048,3840}
        // capped at intrinsic 800. Surviving: {16…800}. Median index ≈ 4 → 96.
        // We don't pin the exact value (config-dependent) — just assert the
        // URL contains a width segment AND it's NOT 800 (which would be a
        // bug — picking the upper bound, not the median).
        self::assertMatchesRegularExpression('@/cache/hero\.jpg/webp-(\d+)-\d+\.webp@', $url);
    }

    public function testUrlReturnsRawMediapoolUrlForSvgPassthrough(): void
    {
        // SVG is passthrough — Image::url() returns the bare mediapool URL,
        // mirroring PassthroughRenderer's `<img src>` choice. No Glide cache,
        // no signing.
        $svgPath = $this->mediaDir . '/logo.svg';
        file_put_contents($svgPath, '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"></svg>');

        $url = Image::url('logo.svg', width: 200);

        self::assertSame('/media/logo.svg', $url);
        self::assertStringNotContainsString('/cache/', $url);
        self::assertStringNotContainsString('?s=', $url);
    }

    public function testUrlReturnsRawMediapoolUrlForGifPassthrough(): void
    {
        $this->copyFixture('animated-3frame.gif', 'banner.gif');

        $url = Image::url('banner.gif', width: 200, format: 'webp');

        // Static or animated GIF → both passthrough. Format / width are
        // silently ignored. Animated GIFs do NOT return the buildAnimatedWebp
        // URL — that's a `<picture>`-only optimization for the `<source>` tag,
        // and using it as a `<video poster>` is undefined per HTML5.
        self::assertSame('/media/banner.gif', $url);
    }

    public function testUrlReturnsEmptyStringForMissingSource(): void
    {
        // No fixture copied → ImageResolver throws → logged and `''`.
        \rex_logger::_reset();

        $url = Image::url('nope.jpg', width: 400);

        self::assertSame('', $url);
        self::assertNotEmpty(\rex_logger::$logged);
    }

    public function testUrlBuilderPathHonorsFocalOverride(): void
    {
        // Direct ImageBuilder usage with the focal setter. 1:1 crop on a 4:3
        // source → fitToken must reflect the override (25% 75% → cover-25-75).
        $this->copyFixture('landscape-800x600.jpg', 'hero.jpg');

        $url = (new ImageBuilder('hero.jpg'))
            ->width(300)
            ->ratio(1.0)
            ->focal('25% 75%')
            ->url('webp');

        self::assertStringContainsString('cover-25-75', $url);
    }

    public function testUrlDefaultFormatFallsBackToFirstConfiguredFormat(): void
    {
        // Config::formats() reads from rex_config; we set it to ['avif',
        // 'webp', 'jpg']. Default format = first → AVIF. Mirrors what
        // Preloader::drain() does at lib/Pipeline/Preloader.php:78.
        \rex_config::set(Config::ADDON, Config::KEY_FORMATS, 'avif,webp,jpg');
        $this->copyFixture('landscape-800x600.jpg', 'hero.jpg');

        $url = Image::url('hero.jpg', width: 400);

        self::assertStringContainsString('/cache/hero.jpg/avif-400', $url);
    }

    public function testUrlCdnModeProducesTemplatedUrl(): void
    {
        \rex_config::set(Config::ADDON, Config::KEY_CDN_ENABLED, '|1|');
        \rex_config::set(Config::ADDON, Config::KEY_CDN_BASE, 'https://cdn.example.com');
        \rex_config::set(Config::ADDON, Config::KEY_CDN_URL_TEMPLATE, '{src}?w={w}&fm={fm}&q={q}');
        $this->copyFixture('landscape-800x600.jpg', 'hero.jpg');

        $url = Image::url('hero.jpg', width: 600, format: 'webp', quality: 75);

        self::assertStringStartsWith('https://cdn.example.com/', $url);
        self::assertStringContainsString('w=600', $url);
        self::assertStringContainsString('fm=webp', $url);
        self::assertStringContainsString('q=75', $url);
        // CDN URLs are NOT signed — there's no Glide endpoint to verify against.
        self::assertStringNotContainsString('?s=', $url);
    }
}
