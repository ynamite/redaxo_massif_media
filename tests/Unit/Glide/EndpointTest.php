<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Glide;

use PHPUnit\Framework\TestCase;
use Ynamite\Media\Glide\Endpoint;

final class EndpointTest extends TestCase
{
    public function testParseCachePathLegacyShape(): void
    {
        $parsed = Endpoint::parseCachePath('hero.jpg/avif-1080-50.avif');
        self::assertNotNull($parsed);
        self::assertSame('hero.jpg', $parsed['source']);
        self::assertSame('avif', $parsed['fmt']);
        self::assertSame(1080, $parsed['w']);
        self::assertSame(50, $parsed['q']);
        self::assertNull($parsed['h']);
        self::assertNull($parsed['fit']);
        self::assertNull($parsed['hash']);
    }

    public function testParseCachePathCropShapeCover(): void
    {
        $parsed = Endpoint::parseCachePath('hero.jpg/avif-1080-1080-cover-50-50-50.avif');
        self::assertNotNull($parsed);
        self::assertSame('hero.jpg', $parsed['source']);
        self::assertSame('avif', $parsed['fmt']);
        self::assertSame(1080, $parsed['w']);
        self::assertSame(1080, $parsed['h']);
        self::assertSame(50, $parsed['q']);
        self::assertSame('cover-50-50', $parsed['fit']);
        self::assertNull($parsed['hash']);
    }

    public function testParseCachePathCropShapeContain(): void
    {
        $parsed = Endpoint::parseCachePath('hero.jpg/webp-800-600-contain-75.webp');
        self::assertNotNull($parsed);
        self::assertSame('contain', $parsed['fit']);
        self::assertSame(800, $parsed['w']);
        self::assertSame(600, $parsed['h']);
    }

    public function testParseCachePathCropShapeStretch(): void
    {
        $parsed = Endpoint::parseCachePath('hero.jpg/jpg-800-450-stretch-80.jpg');
        self::assertNotNull($parsed);
        self::assertSame('stretch', $parsed['fit']);
    }

    public function testParseCachePathFilterShape(): void
    {
        $parsed = Endpoint::parseCachePath('hero.jpg/jpg-800-80-fa1b2c3d4.jpg');
        self::assertNotNull($parsed);
        self::assertSame('a1b2c3d4', $parsed['hash']);
        self::assertNull($parsed['fit']);
        self::assertSame('hero.jpg', $parsed['source']);
        self::assertSame(800, $parsed['w']);
        self::assertSame(80, $parsed['q']);
    }

    public function testParseCachePathCropAndFilters(): void
    {
        $parsed = Endpoint::parseCachePath('hero.jpg/avif-1080-1080-contain-50-fa1b2c3d4.avif');
        self::assertNotNull($parsed);
        self::assertSame('contain', $parsed['fit']);
        self::assertSame('a1b2c3d4', $parsed['hash']);
    }

    public function testParseCachePathPreservesSubdirSource(): void
    {
        $parsed = Endpoint::parseCachePath('gallery/2024/atelier.jpg/avif-1920-50.avif');
        self::assertNotNull($parsed);
        self::assertSame('gallery/2024/atelier.jpg', $parsed['source']);
    }

    public function testParseCachePathRejectsMalformed(): void
    {
        self::assertNull(Endpoint::parseCachePath('garbage'));
        self::assertNull(Endpoint::parseCachePath('hero.jpg/avif-x-50.avif'));
        self::assertNull(Endpoint::parseCachePath('hero.jpg/avif-1080-1080-bogus-50.avif'));
        self::assertNull(Endpoint::parseCachePath('no-extension'));
    }

    public function testParseCachePathRejectsBogusHash(): void
    {
        // Hash must be 8 lowercase hex chars.
        self::assertNull(Endpoint::parseCachePath('hero.jpg/jpg-800-80-fNOTHEX.jpg'));
        self::assertNull(Endpoint::parseCachePath('hero.jpg/jpg-800-80-fab.jpg'));
    }

    public function testParseCachePathReturnsNullForAnimatedShape(): void
    {
        // Animated WebP variants use a flat single-segment stem ("animated")
        // rather than the {fmt}-{w}-{q} shape — Endpoint::handle dispatches
        // these to AnimatedWebpEncoder before parseCachePath is called, so
        // parseCachePath should fail-closed (return null) rather than misparse
        // them as malformed Glide variants.
        self::assertNull(Endpoint::parseCachePath('spinner.gif/animated.webp'));
        self::assertNull(Endpoint::parseCachePath('subdir/anim.gif/animated.webp'));
    }
}
