<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Glide;

use PHPUnit\Framework\TestCase;
use Ynamite\Media\Glide\Endpoint;

final class EndpointTest extends TestCase
{
    public function testParseCachePathLegacyShape(): void
    {
        $parsed = Endpoint::parseCachePath('avif-1080-50/hero.jpg.avif');
        self::assertSame([
            'fmt' => 'avif',
            'w' => 1080,
            'q' => 50,
            'h' => null,
            'fit' => null,
            'source' => 'hero.jpg',
        ], $parsed);
    }

    public function testParseCachePathCropShapeCover(): void
    {
        $parsed = Endpoint::parseCachePath('avif-1080-1080-cover-50-50-50/hero.jpg.avif');
        self::assertSame([
            'fmt' => 'avif',
            'w' => 1080,
            'q' => 50,
            'h' => 1080,
            'fit' => 'cover-50-50',
            'source' => 'hero.jpg',
        ], $parsed);
    }

    public function testParseCachePathCropShapeContain(): void
    {
        $parsed = Endpoint::parseCachePath('webp-800-600-contain-75/hero.jpg.webp');
        self::assertNotNull($parsed);
        self::assertSame('contain', $parsed['fit']);
        self::assertSame(800, $parsed['w']);
        self::assertSame(600, $parsed['h']);
    }

    public function testParseCachePathCropShapeStretch(): void
    {
        $parsed = Endpoint::parseCachePath('jpg-800-450-stretch-80/hero.jpg.jpg');
        self::assertNotNull($parsed);
        self::assertSame('stretch', $parsed['fit']);
    }

    public function testParseCachePathRejectsMalformed(): void
    {
        self::assertNull(Endpoint::parseCachePath('garbage'));
        self::assertNull(Endpoint::parseCachePath('avif-1080-1080-bogus-50/hero.jpg.avif'));
        self::assertNull(Endpoint::parseCachePath('avif-1080-50'));
        self::assertNull(Endpoint::parseCachePath('avif-x-50/hero.jpg.avif'));
    }

    public function testParseCachePathPreservesSourceWithSubdirs(): void
    {
        $parsed = Endpoint::parseCachePath('avif-1080-50/gallery/2024/hero.jpg.avif');
        self::assertNotNull($parsed);
        self::assertSame('gallery/2024/hero.jpg', $parsed['source']);
    }
}
