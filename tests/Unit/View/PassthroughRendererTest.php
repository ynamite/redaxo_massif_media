<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\View;

use PHPUnit\Framework\TestCase;
use rex_config;
use rex_path;
use Ynamite\Media\Config;
use Ynamite\Media\Enum\Decoding;
use Ynamite\Media\Enum\Loading;
use Ynamite\Media\Pipeline\ResolvedImage;
use Ynamite\Media\Pipeline\UrlBuilder;
use Ynamite\Media\Source\MediapoolSource;
use Ynamite\Media\View\PassthroughRenderer;

final class PassthroughRendererTest extends TestCase
{
    protected function tearDown(): void
    {
        rex_config::_reset();
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

    public function testWidthOverrideKeepsIntrinsicHeightWhenNoRatio(): void
    {
        $html = (new PassthroughRenderer())->render($this->svg(), width: 400, alt: 'x');

        self::assertStringContainsString('width="400"', $html);
        self::assertStringContainsString('height="100"', $html);
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
        self::assertMatchesRegularExpression('/<img[^>]+src="[^"]+spinner\.gif"/', $html);
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
}
