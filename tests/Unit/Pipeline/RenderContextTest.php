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
use Ynamite\Media\Source\MediapoolSource;

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
            source: new MediapoolSource(filename: 'hero.jpg', absolutePath: '/tmp/hero.jpg', mtime: 1_700_000_000),
            intrinsicWidth: $w,
            intrinsicHeight: $h,
            mime: 'image/jpeg',
            sourceFormat: 'jpg',
            focalPoint: $focal,
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

    public function testResolveSingleVariantReturnsExplicitWidth(): void
    {
        // Explicit width arg wins — no median lookup. No ratio → no fitToken,
        // no targetHeight.
        [$w, $h, $token] = RenderContext::resolveSingleVariant(
            image: $this->image(1600, 900),
            width: 800,
            height: null,
            ratio: null,
            fit: null,
            srcsetBuilder: new SrcsetBuilder(),
        );

        self::assertSame(800, $w);
        self::assertNull($h);
        self::assertNull($token);
    }

    public function testResolveSingleVariantPicksMedianFromCappedPool(): void
    {
        // 5000×4000 source, 1:1 crop → effectiveMaxWidth=4000. With config
        // pool [16,32,48,64,96,128,256,384,640,750,828,1080,1200,1920,2048,3840]
        // capped at 4000 (and intrinsic 5000 doesn't shrink it further), the
        // surviving sorted pool ends with 3840 then 4000-cap. Median index =
        // floor((count-1)/2). What matters: result is in the pool AND ≤ 4000.
        [$w, , $token] = RenderContext::resolveSingleVariant(
            image: $this->image(5000, 4000),
            width: null,
            height: null,
            ratio: 1.0,
            fit: Fit::COVER,
            srcsetBuilder: new SrcsetBuilder(),
        );

        self::assertLessThanOrEqual(4000, $w);
        self::assertGreaterThan(0, $w);
        self::assertSame('cover-50-50', $token);
    }

    public function testResolveSingleVariantComputesHeightFromRatioWhenCropping(): void
    {
        // 1:1 crop on 1600×900: ratio=1.0, fitToken set, so target height
        // must be round(width / 1.0) = width.
        [$w, $h, $token] = RenderContext::resolveSingleVariant(
            image: $this->image(1600, 900),
            width: 600,
            height: null,
            ratio: 1.0,
            fit: Fit::COVER,
            srcsetBuilder: new SrcsetBuilder(),
        );

        self::assertSame(600, $w);
        self::assertSame(600, $h);
        self::assertSame('cover-50-50', $token);
    }

    public function testResolveSingleVariantOmitsHeightWhenRatioMatchesIntrinsic(): void
    {
        // 1600×900 has intrinsic ratio 16:9 ≈ 1.7777…. Requesting 16/9 falls
        // inside RATIO_EQUAL_EPSILON so no crop is needed → fitToken=null,
        // targetHeight=null. Picture path makes the same choice (no crop
        // segment in cache path), so URL paths must agree.
        [$w, $h, $token] = RenderContext::resolveSingleVariant(
            image: $this->image(1600, 900),
            width: 800,
            height: null,
            ratio: 16 / 9,
            fit: Fit::COVER,
            srcsetBuilder: new SrcsetBuilder(),
        );

        self::assertSame(800, $w);
        self::assertNull($h);
        self::assertNull($token);
    }

    public function testResolveSingleVariantPropagatesFocalPoint(): void
    {
        [, , $token] = RenderContext::resolveSingleVariant(
            image: $this->image(1600, 900, focal: '25% 75%'),
            width: 400,
            height: null,
            ratio: 1.0,
            fit: Fit::COVER,
            srcsetBuilder: new SrcsetBuilder(),
        );

        self::assertSame('cover-25-75', $token);
    }

    public function testResolveSingleVariantHonorsExplicitHeight(): void
    {
        // Explicit width AND height → ratio derived = 800/600. Fit defaults to
        // COVER (since ratio is set), but 800/600 ≠ 1600/900 → crop applies.
        [$w, $h, $token] = RenderContext::resolveSingleVariant(
            image: $this->image(1600, 900),
            width: 800,
            height: 600,
            ratio: null,
            fit: null,
            srcsetBuilder: new SrcsetBuilder(),
        );

        self::assertSame(800, $w);
        self::assertSame(600, $h);
        self::assertSame('cover-50-50', $token);
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

    // --- AVIF minimum-dimension filter --------------------------------------

    public function testAvifSrcsetDropsWidthsWhereCroppedHeightFallsBelowSixteen(): void
    {
        // 16:9 ratio + crop. With image_sizes 16,32,48,64,…:
        //   w=16  → h=9   (drop, h<16)
        //   w=32  → h=18  (keep)
        //   w=48  → h=27  (keep)
        // libavif's AV1 floor is 16×16; below that the encoder produces an
        // empty blob. Filtering at srcset-emission time keeps the AVIF
        // <source> from advertising URLs the cache-miss endpoint can't fulfil.
        $ctx = RenderContext::build(
            image: $this->image(1600, 900),
            width: null,
            height: null,
            ratio: 16.0 / 9.0,
            fit: Fit::COVER,
            widthsOverride: [16, 32, 48],
            srcsetBuilder: new SrcsetBuilder(),
        );

        $srcset = $ctx->buildSrcset(
            urlBuilder: new UrlBuilder(),
            image: $this->image(1600, 900),
            format: 'avif',
            quality: null,
            filterParams: [],
        );

        self::assertStringNotContainsString('avif-16-', $srcset, '16w AVIF must be filtered out (h=9 < 16).');
        self::assertStringContainsString('avif-32-', $srcset, '32w AVIF must remain (h=18).');
        self::assertStringContainsString('avif-48-', $srcset, '48w AVIF must remain (h=27).');
    }

    public function testWebpSrcsetKeepsAllWidthsAtSixteenByNineRatio(): void
    {
        // The min-dimension filter is AVIF-specific; libwebp/libjpeg accept
        // arbitrarily small inputs. WebP and JPG retain the full width pool
        // so the browser still has 16w fallbacks for tiny image slots.
        $ctx = RenderContext::build(
            image: $this->image(1600, 900),
            width: null,
            height: null,
            ratio: 16.0 / 9.0,
            fit: Fit::COVER,
            widthsOverride: [16, 32],
            srcsetBuilder: new SrcsetBuilder(),
        );

        $srcset = $ctx->buildSrcset(
            urlBuilder: new UrlBuilder(),
            image: $this->image(1600, 900),
            format: 'webp',
            quality: null,
            filterParams: [],
        );

        self::assertStringContainsString('webp-16-', $srcset);
        self::assertStringContainsString('webp-32-', $srcset);
    }

    public function testAvifSrcsetKeepsSixteenWidthOnSquareSource(): void
    {
        // Square source, square crop: w=16 → h=16, exactly at the AV1 floor
        // (16×16 is allowed). Keep.
        $ctx = RenderContext::build(
            image: $this->image(800, 800),
            width: null,
            height: null,
            ratio: 1.0,
            fit: Fit::COVER,
            widthsOverride: [16, 32],
            srcsetBuilder: new SrcsetBuilder(),
        );

        $srcset = $ctx->buildSrcset(
            urlBuilder: new UrlBuilder(),
            image: $this->image(800, 800),
            format: 'avif',
            quality: null,
            filterParams: [],
        );

        self::assertStringContainsString('avif-16-', $srcset);
        self::assertStringContainsString('avif-32-', $srcset);
    }

    public function testAvifSrcsetUsesSourceIntrinsicRatioWhenNoCrop(): void
    {
        // No explicit ratio + Fit::NONE → the variant scales width with
        // source's intrinsic 1600/900 ≈ 1.778. At w=16, intrinsic-scaled
        // h = round(16 / 1.778) = 9. Drop AVIF for that variant even though
        // there's no `fitToken` and the cache path won't carry an explicit
        // height — Glide's serve-time scale produces exactly 16×9, hits
        // libavif's floor, returns empty.
        $ctx = RenderContext::build(
            image: $this->image(1600, 900),
            width: null,
            height: null,
            ratio: null,
            fit: Fit::NONE,
            widthsOverride: [16, 64],
            srcsetBuilder: new SrcsetBuilder(),
        );

        $srcset = $ctx->buildSrcset(
            urlBuilder: new UrlBuilder(),
            image: $this->image(1600, 900),
            format: 'avif',
            quality: null,
            filterParams: [],
        );

        self::assertStringNotContainsString('avif-16-', $srcset);
        self::assertStringContainsString('avif-64-', $srcset);
    }

    // --- Cache-generation token in URLs -------------------------------------

    public function testBuildSrcsetEmitsCacheGenerationToken(): void
    {
        rex_config::set(Config::ADDON, Config::KEY_CACHE_GENERATION, 1_700_000_000);
        // Reset Config's request-cached static so the new value is picked up.
        $cacheGenProp = new \ReflectionProperty(Config::class, 'cacheGenerationCache');
        $cacheGenProp->setValue(null, null);

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

        // Browser-cache-busting token from Config::cacheGeneration() shows
        // up as `&g=<int>` after the existing `?s=…&v=…` query segments.
        self::assertStringContainsString('&g=1700000000', $srcset);
    }
}
