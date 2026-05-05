<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Pipeline;

use PHPUnit\Framework\TestCase;
use rex_config;
use Ynamite\Media\Config;
use Ynamite\Media\Enum\Fit;
use Ynamite\Media\Pipeline\RenderContext;
use Ynamite\Media\Pipeline\ResolvedImage;
use Ynamite\Media\Pipeline\SrcsetBuilder;
use Ynamite\Media\Pipeline\UrlBuilder;

final class RenderContextTest extends TestCase
{
    protected function setUp(): void
    {
        rex_config::set(Config::ADDON, Config::KEY_SIGN_KEY, 'unit-test-key');
        rex_config::set(Config::ADDON, Config::KEY_DEVICE_SIZES, '640,750,828,1080,1200,1920,2048,3840');
        rex_config::set(Config::ADDON, Config::KEY_IMAGE_SIZES, '16,32,48,64,96,128,256,384');
    }

    protected function tearDown(): void
    {
        rex_config::_reset();
    }

    private function image(int $w = 1600, int $h = 900, ?string $focal = null): ResolvedImage
    {
        return new ResolvedImage(
            sourcePath: 'hero.jpg',
            absolutePath: '/tmp/hero.jpg',
            intrinsicWidth: $w,
            intrinsicHeight: $h,
            mime: 'image/jpeg',
            sourceFormat: 'jpg',
            focalPoint: $focal,
            mtime: 1_700_000_000,
        );
    }

    public function testBuildResolvesExplicitRatio(): void
    {
        $ctx = RenderContext::build(
            image: $this->image(1600, 900),
            width: null,
            height: null,
            ratio: 1.0,
            fit: Fit::COVER,
            widthsOverride: [400, 800, 1200],
            srcsetBuilder: new SrcsetBuilder(),
        );

        self::assertSame(1.0, $ctx->effectiveRatio);
        self::assertSame(Fit::COVER, $ctx->effectiveFit);
        self::assertNotNull($ctx->fitToken);
        self::assertSame('cover-50-50', $ctx->fitToken);
    }

    public function testBuildDerivesRatioFromWidthAndHeight(): void
    {
        $ctx = RenderContext::build(
            image: $this->image(1600, 900),
            width: 800,
            height: 600,
            ratio: null,
            fit: null,
            widthsOverride: [400, 800],
            srcsetBuilder: new SrcsetBuilder(),
        );

        self::assertEqualsWithDelta(800 / 600, $ctx->effectiveRatio, 1e-9);
        self::assertSame(Fit::COVER, $ctx->effectiveFit);
        self::assertSame('cover-50-50', $ctx->fitToken);
    }

    public function testBuildSkipsFitTokenWhenRatioMatchesIntrinsicWithinEpsilon(): void
    {
        // 1600×900 has intrinsic ratio 16:9 ≈ 1.7777…; requesting 16/9 falls
        // inside RATIO_EQUAL_EPSILON, so cropping is a no-op and we should
        // emit no fit-token (avoids cache-path divergence between renderer
        // and preloader).
        $ctx = RenderContext::build(
            image: $this->image(1600, 900),
            width: null,
            height: null,
            ratio: 16 / 9,
            fit: Fit::COVER,
            widthsOverride: [400, 800],
            srcsetBuilder: new SrcsetBuilder(),
        );

        self::assertNull($ctx->fitToken);
        self::assertNull($ctx->effectiveMaxWidth);
    }

    public function testBuildDefaultsFitToNoneWhenNoRatio(): void
    {
        $ctx = RenderContext::build(
            image: $this->image(1600, 900),
            width: null,
            height: null,
            ratio: null,
            fit: null,
            widthsOverride: [400, 800],
            srcsetBuilder: new SrcsetBuilder(),
        );

        self::assertNull($ctx->effectiveRatio);
        self::assertSame(Fit::NONE, $ctx->effectiveFit);
        self::assertNull($ctx->fitToken);
    }

    public function testBuildAppliesEffectiveMaxWidthCapForCover(): void
    {
        // 5000×4000 source, 1:1 crop. Cap = min(5000, 4000*1) = 4000.
        // Override pool intentionally exceeds the cap; only widths ≤ 4000
        // (plus the cap itself) survive.
        $ctx = RenderContext::build(
            image: $this->image(5000, 4000),
            width: null,
            height: null,
            ratio: 1.0,
            fit: Fit::COVER,
            widthsOverride: [1000, 2000, 4000, 4500, 5000],
            srcsetBuilder: new SrcsetBuilder(),
        );

        self::assertSame(4000, $ctx->effectiveMaxWidth);
        self::assertSame([1000, 2000, 4000], $ctx->widths);
    }

    public function testBuildSkipsMaxWidthCapForStretch(): void
    {
        // STRETCH is exempt — Glide can squish to any size, so the pool is
        // capped at intrinsic width only.
        $ctx = RenderContext::build(
            image: $this->image(5000, 4000),
            width: null,
            height: null,
            ratio: 1.0,
            fit: Fit::STRETCH,
            widthsOverride: [1000, 4000, 5000],
            srcsetBuilder: new SrcsetBuilder(),
        );

        self::assertNull($ctx->effectiveMaxWidth);
        self::assertSame([1000, 4000, 5000], $ctx->widths);
    }

    public function testBuildHonorsExplicitFitNone(): void
    {
        // fit=NONE means: just use the layout box, never crop. fitToken stays null.
        $ctx = RenderContext::build(
            image: $this->image(1600, 900),
            width: 800,
            height: 600,
            ratio: null,
            fit: Fit::NONE,
            widthsOverride: [400, 800],
            srcsetBuilder: new SrcsetBuilder(),
        );

        self::assertEqualsWithDelta(800 / 600, $ctx->effectiveRatio, 1e-9);
        self::assertSame(Fit::NONE, $ctx->effectiveFit);
        self::assertNull($ctx->fitToken);
        self::assertNull($ctx->effectiveMaxWidth);
    }

    public function testBuildPropagatesFocalPointIntoFitToken(): void
    {
        $ctx = RenderContext::build(
            image: $this->image(1600, 900, focal: '25% 75%'),
            width: null,
            height: null,
            ratio: 1.0,
            fit: Fit::COVER,
            widthsOverride: [400],
            srcsetBuilder: new SrcsetBuilder(),
        );

        self::assertSame('cover-25-75', $ctx->fitToken);
    }

    public function testBuildSrcsetEmitsWidthDescriptorPerWidth(): void
    {
        // intrinsicWidth=1200 keeps SrcsetBuilder's cap from appending a 4th
        // entry beyond the override [400, 800, 1200].
        $img = $this->image(1200, 800);
        $ctx = RenderContext::build(
            image: $img,
            width: null,
            height: null,
            ratio: null,
            fit: Fit::NONE,
            widthsOverride: [400, 800, 1200],
            srcsetBuilder: new SrcsetBuilder(),
        );

        $srcset = $ctx->buildSrcset(
            urlBuilder: new UrlBuilder(),
            image: $img,
            format: 'webp',
            quality: 75,
            filterParams: [],
        );

        $parts = explode(', ', $srcset);
        self::assertCount(3, $parts);
        self::assertStringEndsWith(' 400w', $parts[0]);
        self::assertStringEndsWith(' 800w', $parts[1]);
        self::assertStringEndsWith(' 1200w', $parts[2]);
    }

    public function testBuildSrcsetIncludesHeightWhenCropping(): void
    {
        // 1:1 crop on a 1600×900 source → fitToken set, effectiveRatio=1.0.
        // For width=400, height=round(400/1.0)=400 → URL must include the
        // 400-400-cover- segment.
        $ctx = RenderContext::build(
            image: $this->image(1600, 900),
            width: null,
            height: null,
            ratio: 1.0,
            fit: Fit::COVER,
            widthsOverride: [400],
            srcsetBuilder: new SrcsetBuilder(),
        );

        $srcset = $ctx->buildSrcset(
            urlBuilder: new UrlBuilder(),
            image: $this->image(1600, 900),
            format: 'webp',
            quality: null,
            filterParams: [],
        );

        self::assertStringContainsString('webp-400-400-cover-50-50', $srcset);
    }

    public function testBuildSrcsetOmitsHeightWhenNotCropping(): void
    {
        $ctx = RenderContext::build(
            image: $this->image(1600, 900),
            width: null,
            height: null,
            ratio: null,
            fit: Fit::NONE,
            widthsOverride: [400],
            srcsetBuilder: new SrcsetBuilder(),
        );

        $srcset = $ctx->buildSrcset(
            urlBuilder: new UrlBuilder(),
            image: $this->image(1600, 900),
            format: 'webp',
            quality: null,
            filterParams: [],
        );

        self::assertStringNotContainsString('cover-', $srcset);
        self::assertStringContainsString('webp-400', $srcset);
    }
}
