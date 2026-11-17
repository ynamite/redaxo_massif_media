<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\View;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use rex_config;
use rex_path;
use Ynamite\Media\Config;
use Ynamite\Media\Enum\Decoding;
use Ynamite\Media\Enum\FetchPriority;
use Ynamite\Media\Enum\Loading;
use Ynamite\Media\Pipeline\ResolvedImage;
use Ynamite\Media\Pipeline\UrlBuilder;
use Ynamite\Media\Source\MediapoolSource;
use Ynamite\Media\Source\ExternalSource;
use Ynamite\Media\Glide\Signature;
use Ynamite\Media\View\PassthroughRenderer;

final class PassthroughRendererTest extends TestCase
{
    protected function tearDown(): void
    {
        rex_config::_reset();
        (new \ReflectionProperty(Config::class, 'cacheGenerationCache'))->setValue(null, null);
    }

    private function svg(): ResolvedImage
    {
        return new ResolvedImage(
            source: new MediapoolSource(filename: 'logo.svg', absolutePath: '/tmp/logo.svg', mtime: 0),
            intrinsicWidth: 200,
            intrinsicHeight: 100,
            mime: 'image/svg+xml',
            sourceFormat: 'svg',
        );
    }

    private function animatedGif(): ResolvedImage
    {
        return new ResolvedImage(
            source: new MediapoolSource(filename: 'spinner.gif', absolutePath: '/tmp/spinner.gif', mtime: 1_700_000_000),
            intrinsicWidth: 200,
            intrinsicHeight: 200,
            mime: 'image/gif',
            sourceFormat: 'gif',
            isAnimated: true,
        );
    }

    public function testEmitsImgTagWithIntrinsicDimsByDefault(): void
    {
        $html = (new PassthroughRenderer())->render($this->svg(), alt: 'Hero');

        self::assertStringStartsWith('<img', $html);
        self::assertStringContainsString('src="/media/logo.svg"', $html);
        self::assertStringContainsString('width="200"', $html);
        self::assertStringContainsString('height="100"', $html);
        self::assertStringContainsString('alt="Hero"', $html);
        self::assertStringNotContainsString('aria-hidden', $html);
    }

    public function testWidthOverrideScalesIntrinsicHeightWhenNoRatio(): void
    {
        $html = (new PassthroughRenderer())->render($this->svg(), width: 400, alt: 'x');

        self::assertStringContainsString('width="400"', $html);
        self::assertStringContainsString('height="200"', $html);
    }

    public function testHeightOverrideTakesPrecedenceOverRatio(): void
    {
        $html = (new PassthroughRenderer())->render($this->svg(), width: 400, height: 250, ratio: 4.0, alt: 'x');

        self::assertStringContainsString('width="400"', $html);
        self::assertStringContainsString('height="250"', $html);
    }

    public function testRatioDerivesHeightFromWidthWhenHeightOmitted(): void
    {
        $html = (new PassthroughRenderer())->render($this->svg(), width: 400, ratio: 2.0, alt: 'x');

        self::assertStringContainsString('width="400"', $html);
        self::assertStringContainsString('height="200"', $html);
    }

    public function testNullAltAddsAriaHidden(): void
    {
        $html = (new PassthroughRenderer())->render($this->svg());

        self::assertStringContainsString('alt=""', $html);
        self::assertStringContainsString('aria-hidden="true"', $html);
    }

    public function testEmptyAltAddsAriaHidden(): void
    {
        $html = (new PassthroughRenderer())->render($this->svg(), alt: '');

        self::assertStringContainsString('aria-hidden="true"', $html);
    }

    public function testNonEmptyAltOmitsAriaHidden(): void
    {
        $html = (new PassthroughRenderer())->render($this->svg(), alt: 'x');

        self::assertStringNotContainsString('aria-hidden', $html);
    }

    public function testClassAttributeAppearsWhenSet(): void
    {
        $html = (new PassthroughRenderer())->render($this->svg(), alt: 'x', class: 'hero-img u-cover');

        self::assertStringContainsString('class="hero-img u-cover"', $html);
    }

    public function testEmptyClassIsOmitted(): void
    {
        $html = (new PassthroughRenderer())->render($this->svg(), alt: 'x', class: '');

        self::assertStringNotContainsString('class=', $html);
    }

    public function testLoadingAndDecodingEnumsAppear(): void
    {
        $html = (new PassthroughRenderer())->render(
            $this->svg(),
            alt: 'x',
            loading: Loading::EAGER,
            decoding: Decoding::SYNC,
        );

        self::assertStringContainsString('loading="eager"', $html);
        self::assertStringContainsString('decoding="sync"', $html);
    }

    public function testAltIsHtmlEscaped(): void
    {
        $html = (new PassthroughRenderer())->render($this->svg(), alt: 'A & B "quoted"');

        self::assertStringContainsString('alt="A &amp; B &quot;quoted&quot;"', $html);
    }

    // --- Animated WebP wrap (B2) -------------------------------------------

    public function testAnimatedGifWithUrlBuilderEmitsPictureWithWebpSource(): void
    {
        rex_path::_setBase(sys_get_temp_dir() . '/massif_passthrough_anim_' . uniqid('', true));
        rex_config::set(Config::ADDON, Config::KEY_SIGN_KEY, 'k');

        $html = (new PassthroughRenderer(new UrlBuilder()))->render($this->animatedGif(), alt: 'Loading');

        // Wrapped in <picture>, WebP source first, GIF <img> as fallback.
        self::assertStringStartsWith('<picture>', $html);
        self::assertStringEndsWith('</picture>', $html);
        self::assertStringContainsString('<source type="image/webp"', $html);
        self::assertStringContainsString('/animated.webp', $html);
        self::assertStringContainsString('src="/media/spinner.gif?v=1700000000"', $html);
    }

    public function testAnimatedGifWithoutUrlBuilderFallsBackToPlainImg(): void
    {
        $html = (new PassthroughRenderer())->render($this->animatedGif(), alt: 'x');

        self::assertStringNotContainsString('<picture>', $html);
        self::assertStringNotContainsString('animated.webp', $html);
        self::assertStringStartsWith('<img', $html);
    }

    public function testAnimatedGifInCdnModeFallsBackToPlainImg(): void
    {
        rex_config::set(Config::ADDON, Config::KEY_CDN_ENABLED, 1);

        $html = (new PassthroughRenderer(new UrlBuilder()))->render($this->animatedGif(), alt: 'x');

        // CDN mode: UrlBuilder::buildAnimatedWebp returns '' so we don't wrap.
        self::assertStringNotContainsString('<picture>', $html);
        self::assertStringNotContainsString('animated.webp', $html);
    }

    public function testNonAnimatedGifNeverEmitsAnimatedWebpWrap(): void
    {
        rex_path::_setBase(sys_get_temp_dir() . '/massif_passthrough_static_' . uniqid('', true));
        $static = new ResolvedImage(
            source: new MediapoolSource(filename: 'static.gif', absolutePath: '/tmp/static.gif', mtime: 0),
            intrinsicWidth: 100,
            intrinsicHeight: 100,
            mime: 'image/gif',
            sourceFormat: 'gif',
            isAnimated: false,
        );

        $html = (new PassthroughRenderer(new UrlBuilder()))->render($static, alt: 'x');

        self::assertStringNotContainsString('<picture>', $html);
    }

    public function testSvgFlaggedAnimatedDoesNotGetWebpWrap(): void
    {
        // SVGs can technically have SMIL animation but the WebP encoder
        // rejects non-GIF sources, so UrlBuilder gates the same way.
        $animatedSvg = new ResolvedImage(
            source: new MediapoolSource(filename: 'logo.svg', absolutePath: '/tmp/logo.svg', mtime: 0),
            intrinsicWidth: 100,
            intrinsicHeight: 100,
            mime: 'image/svg+xml',
            sourceFormat: 'svg',
            isAnimated: true,
        );

        $html = (new PassthroughRenderer(new UrlBuilder()))->render($animatedSvg, alt: 'x');

        self::assertStringNotContainsString('<picture>', $html);
        self::assertStringNotContainsString('animated.webp', $html);
    }

    private function responsiveImage(string $format = 'gif', int $frames = 3): ResolvedImage
    {
        return new ResolvedImage(
            new MediapoolSource('banner.' . $format, '/tmp/banner.' . $format, 1_700_000_000),
            1600, 900, 'image/' . $format, $format,
            isAnimated: $frames > 1, frameCount: $frames,
        );
    }

    public function testAnimatedGifGetsWidthSrcset(): void
    {
        rex_config::set(Config::ADDON, Config::KEY_SIGN_KEY, 'k');
        $html = (new PassthroughRenderer(new UrlBuilder()))->render($this->responsiveImage());

        self::assertStringStartsWith('<picture><source type="image/webp"', $html);
        foreach ([640, 1280, 1600] as $width) {
            self::assertStringContainsString(' ' . $width . 'w', $html);
        }
        self::assertStringContainsString('/animated-640.webp?', $html);
        self::assertStringContainsString('/animated-1280.webp?', $html);
        self::assertStringContainsString('/animated.webp?', $html);
        self::assertStringContainsString('sizes="' . Config::defaultSizes() . '"', $html);
        self::assertStringContainsString('<img src="/media/banner.gif?v=1700000000"', $html);
    }

    public function testAnimatedWebpSourceGetsSrcset(): void
    {
        rex_config::set(Config::ADDON, Config::KEY_SIGN_KEY, 'k');
        $html = (new PassthroughRenderer(new UrlBuilder()))->render($this->responsiveImage('webp'));

        self::assertStringStartsWith('<picture>', $html);
        self::assertStringContainsString(' 640w', $html);
        self::assertStringContainsString(' 1280w', $html);
        self::assertStringContainsString(' 1600w', $html);
        self::assertStringContainsString('<img src="/media/banner.webp?v=1700000000"', $html);
    }

    public function testExplicitSizesAreEscaped(): void
    {
        rex_config::set(Config::ADDON, Config::KEY_SIGN_KEY, 'k');
        $html = (new PassthroughRenderer(new UrlBuilder()))->render(
            $this->responsiveImage(), sizes: '(max-width: 600px) 100vw, 50vw',
        );
        self::assertStringContainsString('sizes="(max-width: 600px) 100vw, 50vw"', $html);
        self::assertStringNotContainsString('auto,', $html);
    }

    public function testOverBudgetIsPlainImg(): void
    {
        rex_config::set(Config::ADDON, Config::KEY_SIGN_KEY, 'k');
        $html = (new PassthroughRenderer(new UrlBuilder()))->render($this->responsiveImage(frames: 380));

        self::assertStringStartsWith('<img src="/media/banner.gif?v=1700000000"', $html);
        self::assertStringNotContainsString('<picture>', $html);
        self::assertStringNotContainsString('<source', $html);
    }

    public function testStaticGifIsPlainImg(): void
    {
        $html = (new PassthroughRenderer(new UrlBuilder()))->render($this->responsiveImage(frames: 1));
        self::assertStringStartsWith('<img', $html);
        self::assertStringNotContainsString('<source', $html);
    }

    public function testAnimatedUrlsAreSignedForEachWidthAndCarryCacheTokens(): void
    {
        rex_config::set(Config::ADDON, Config::KEY_SIGN_KEY, 'k');
        rex_config::set(Config::ADDON, Config::KEY_CACHE_GENERATION, 123);
        (new \ReflectionProperty(Config::class, 'cacheGenerationCache'))->setValue(null, null);
        $builder = new UrlBuilder();
        $image = $this->responsiveImage();
        foreach ([640 => 'animated-640.webp', 1280 => 'animated-1280.webp', 1600 => 'animated.webp'] as $width => $filename) {
            $url = $builder->buildAnimatedWebp($image, $width);
            self::assertStringContainsString('/cache/banner.gif/' . $filename . '?s=', $url);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            self::assertTrue(Signature::verify('banner.gif/' . $filename, $query['s']));
            self::assertSame('1700000000', $query['v']);
            self::assertSame('123', $query['g']);
        }
    }

    #[DataProvider('passthroughFormats')]
    public function testWidthOnlyKeepsAspectRatioAndOriginalCacheToken(string $format, bool $animated): void
    {
        $image = new ResolvedImage(
            new MediapoolSource('image.' . $format, '/tmp/image', 1_700_000_000),
            800, 600, 'image/' . $format, $format, isAnimated: $animated,
        );
        $html = (new PassthroughRenderer())->render($image, width: 400);
        self::assertStringContainsString('width="400" height="300"', $html);
        self::assertStringContainsString('src="/media/image.' . $format . '?v=1700000000"', $html);
    }

    public static function passthroughFormats(): array
    {
        return [['svg', false], ['gif', false], ['gif', true], ['webp', true]];
    }

    public function testFocalPointAndFetchPriorityReachImg(): void
    {
        $html = (new PassthroughRenderer())->render(
            $this->svg()->withFocal('25% 75%'), fetchPriority: FetchPriority::HIGH,
        );
        self::assertStringContainsString('style="object-position:25% 75%"', $html);
        self::assertStringContainsString('fetchpriority="high"', $html);
    }

    public function testFocalPointIsEscapedOnPlainImg(): void
    {
        $html = (new PassthroughRenderer())->render($this->svg()->withFocal('25% 75%"'));
        self::assertStringContainsString('style="object-position:25% 75%&quot;"', $html);
    }

    public function testDefaultFetchPriorityAndAbsentFocalAreOmitted(): void
    {
        $html = (new PassthroughRenderer())->render($this->svg());
        self::assertStringNotContainsString('fetchpriority=', $html);
        self::assertStringNotContainsString('style=', $html);
    }

    public function testExternalAnimatedSourceStaysOriginalIncludingQuery(): void
    {
        rex_config::set(Config::ADDON, 'asset_host', 'https://assets.example.com');
        $image = new ResolvedImage(
            new ExternalSource('https://origin.example.com/a.gif?a=1&v=original', 'hash', '/tmp/a', 123, null, null, 3600),
            800, 600, 'image/gif', 'gif', isAnimated: true, frameCount: 3,
        );
        $html = (new PassthroughRenderer(new UrlBuilder()))->render($image, width: 400);
        self::assertStringStartsWith('<img src="https://origin.example.com/a.gif?a=1&amp;v=original"', $html);
        self::assertStringContainsString('width="400" height="300"', $html);
    }

    public function testUnreadableAnimatedDimensionsStayPlainImg(): void
    {
        foreach ([[0, 0, 3], [800, 600, 0]] as [$width, $height, $frames]) {
            $image = new ResolvedImage($this->animatedGif()->source, $width, $height, 'image/gif', 'gif', isAnimated: true, frameCount: $frames);
            self::assertStringStartsWith('<img', (new PassthroughRenderer(new UrlBuilder()))->render($image));
        }
    }
}
