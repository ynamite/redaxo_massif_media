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
}
