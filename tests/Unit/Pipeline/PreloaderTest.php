<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Pipeline;

use PHPUnit\Framework\TestCase;
use rex_config;
use Ynamite\Media\Config;
use Ynamite\Media\Pipeline\Preloader;
use Ynamite\Media\Pipeline\ResolvedImage;
use Ynamite\Media\Source\MediapoolSource;

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
            source: new MediapoolSource(filename: 'hero.jpg', absolutePath: '/tmp/hero.jpg', mtime: 1_700_000_000),
            intrinsicWidth: 1600,
            intrinsicHeight: 900,
            mime: 'image/jpeg',
            sourceFormat: 'jpg',
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
            source: new MediapoolSource(filename: 'logo.svg', absolutePath: '/tmp/logo.svg', mtime: 0),
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

    public function testQueueLinkEmitsRawPreloadShape(): void
    {
        Preloader::queueLink('/media/clip.mp4?v=1', 'video', 'video/mp4');

        $html = Preloader::drain();

        self::assertStringContainsString('<link rel="preload"', $html);
        self::assertStringContainsString('as="video"', $html);
        self::assertStringContainsString('href="/media/clip.mp4?v=1"', $html);
        self::assertStringContainsString('type="video/mp4"', $html);
        self::assertStringContainsString('fetchpriority="high"', $html);
        // Raw links must NOT carry imagesrcset / imagesizes — those are for
        // responsive image preloads only.
        self::assertStringNotContainsString('imagesrcset=', $html);
        self::assertStringNotContainsString('imagesizes=', $html);
    }

    public function testQueueLinkOmitsTypeWhenNull(): void
    {
        Preloader::queueLink('/media/poster.jpg?v=1', 'image');

        $html = Preloader::drain();

        self::assertStringContainsString('as="image"', $html);
        self::assertStringNotContainsString('type=', $html);
    }

    public function testDrainEmitsImageQueueBeforeRawLinks(): void
    {
        // Preload order matters for browser scheduling — image queue (the
        // historical primary path) renders before raw links so existing
        // call sites are unaffected by the new addition.
        Preloader::queueLink('/media/clip.mp4?v=1', 'video', 'video/mp4');
        Preloader::queue($this->image(), widths: [800, 1200], sizes: '100vw');

        $html = Preloader::drain();

        $imagePos = strpos($html, 'as="image"');
        $videoPos = strpos($html, 'as="video"');
        self::assertNotFalse($imagePos);
        self::assertNotFalse($videoPos);
        self::assertLessThan($videoPos, $imagePos);
    }

    public function testQueueLinkResetsAfterDrain(): void
    {
        Preloader::queueLink('/media/clip.mp4?v=1', 'video', 'video/mp4');
        Preloader::drain();

        // Second drain with no further queuing must be empty — confirms the
        // raw queue resets identically to the image queue.
        self::assertSame('', Preloader::drain());
    }

    public function testQueueLinkEscapesAttributes(): void
    {
        Preloader::queueLink('/media/file.mp4?a=1&b=2', 'video', 'video/mp4');

        $html = Preloader::drain();

        self::assertStringContainsString('href="/media/file.mp4?a=1&amp;b=2"', $html);
    }

    public function testDrainCapsWidthsAtEffectiveMaxForCoverCrop(): void
    {
        // 5000×4000 source, 1:1 crop. PictureRenderer's <img srcset> caps at
        // 4000 (min(5000, 4000*1)). If the preloader emits widths above 4000,
        // those URLs aren't in the rendered srcset and the preload is wasted.
        $bigImage = new ResolvedImage(
            source: new MediapoolSource(filename: 'big.jpg', absolutePath: '/tmp/big.jpg', mtime: 1_700_000_000),
            intrinsicWidth: 5000,
            intrinsicHeight: 4000,
            mime: 'image/jpeg',
            sourceFormat: 'jpg',
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
