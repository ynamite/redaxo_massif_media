<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Pipeline;

use PHPUnit\Framework\TestCase;
use rex_config;
use Ynamite\Media\Config;
use Ynamite\Media\Pipeline\Preloader;
use Ynamite\Media\Pipeline\ResolvedImage;

final class PreloaderTest extends TestCase
{
    protected function setUp(): void
    {
        rex_config::set(Config::ADDON, Config::KEY_SIGN_KEY, 'k');
    }

    protected function tearDown(): void
    {
        Preloader::reset();
        rex_config::_reset();
    }

    private function image(): ResolvedImage
    {
        return new ResolvedImage(
            sourcePath: 'hero.jpg',
            absolutePath: '/tmp/hero.jpg',
            intrinsicWidth: 1600,
            intrinsicHeight: 900,
            mime: 'image/jpeg',
            sourceFormat: 'jpg',
            mtime: 1_700_000_000,
        );
    }

    public function testDrainEmitsFetchpriorityHighOnLink(): void
    {
        // Lighthouse's "LCP request discovery" audit fails the
        // fetchpriority=high checkbox unless the preload <link> carries the
        // attribute alongside imagesrcset / imagesizes. Preloading is opt-in
        // and semantically means "this is the above-the-fold hero", so the
        // attribute is always emitted.
        Preloader::queue($this->image(), widths: [800, 1200], sizes: '100vw');

        $html = Preloader::drain();

        self::assertStringContainsString('<link rel="preload"', $html);
        self::assertStringContainsString('fetchpriority="high"', $html);
    }

    public function testDrainEmptyQueueReturnsEmpty(): void
    {
        self::assertSame('', Preloader::drain());
    }

    public function testDrainSkipsPassthroughSources(): void
    {
        // SVG / GIF can't be format-negotiated, no point preloading.
        $svg = new ResolvedImage(
            sourcePath: 'logo.svg',
            absolutePath: '/tmp/logo.svg',
            intrinsicWidth: 100,
            intrinsicHeight: 100,
            mime: 'image/svg+xml',
            sourceFormat: 'svg',
        );
        Preloader::queue($svg);

        self::assertSame('', Preloader::drain());
    }

    public function testDrainOmitsCropTokenWhenRatioMatchesIntrinsic(): void
    {
        // 1600×900 source has intrinsic ratio ≈ 1.7777. Requesting 16:9
        // (= 1.7777…) is within RATIO_EQUAL_EPSILON, so the rendered <img
        // srcset> emits paths WITHOUT a `cover-X-Y` cache-path segment.
        // The preload <link imagesrcset> must match — otherwise the browser
        // can't dedupe the preload fetch with the actual image fetch.
        Preloader::queue(
            $this->image(),
            ratio: 16 / 9,
            widths: [800, 1200],
            sizes: '100vw',
            fit: \Ynamite\Media\Enum\Fit::COVER,
        );

        $html = Preloader::drain();

        self::assertStringNotContainsString('cover-', $html);
        self::assertStringContainsString('<link rel="preload"', $html);
    }

    public function testDrainCapsWidthsAtEffectiveMaxForCoverCrop(): void
    {
        // 5000×4000 source, 1:1 crop. PictureRenderer's <img srcset> caps at
        // 4000 (min(5000, 4000*1)). If the preloader emits widths above 4000,
        // those URLs aren't in the rendered srcset and the preload is wasted.
        $bigImage = new ResolvedImage(
            sourcePath: 'big.jpg',
            absolutePath: '/tmp/big.jpg',
            intrinsicWidth: 5000,
            intrinsicHeight: 4000,
            mime: 'image/jpeg',
            sourceFormat: 'jpg',
            mtime: 1_700_000_000,
        );
        Preloader::queue(
            $bigImage,
            ratio: 1.0,
            widths: [1000, 2000, 4000, 4500, 5000],
            sizes: '100vw',
            fit: \Ynamite\Media\Enum\Fit::COVER,
        );

        $html = Preloader::drain();

        self::assertStringContainsString(' 4000w', $html);
        self::assertStringNotContainsString(' 4500w', $html);
        self::assertStringNotContainsString(' 5000w', $html);
    }
}
