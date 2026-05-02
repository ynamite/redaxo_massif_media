<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Glide;

use PHPUnit\Framework\TestCase;
use Ynamite\Media\Glide\Server;

final class ServerTest extends TestCase
{
    public function testCachePathLegacyShape(): void
    {
        $path = Server::cachePath('hero.jpg', ['fm' => 'avif', 'w' => 1080, 'q' => 50]);
        self::assertSame('avif-1080-50/hero.jpg.avif', $path);
    }

    public function testCachePathCropShape(): void
    {
        $path = Server::cachePath('hero.jpg', [
            'fm' => 'avif', 'w' => 1080, 'q' => 50,
            'h' => 1080, 'fit' => 'cover-50-50',
        ]);
        self::assertSame('avif-1080-1080-cover-50-50-50/hero.jpg.avif', $path);
    }

    public function testCachePathContainShape(): void
    {
        $path = Server::cachePath('hero.jpg', [
            'fm' => 'webp', 'w' => 800, 'q' => 75,
            'h' => 600, 'fit' => 'contain',
        ]);
        self::assertSame('webp-800-600-contain-75/hero.jpg.webp', $path);
    }

    public function testCachePathRoundTripsCoverCrop(): void
    {
        // The cache-path callable inside Glide invokes Server::cachePath with
        // the Glide-translated `crop-X-Y` token. Server::cachePath must
        // normalize back to our `cover-X-Y` form so URL-side and Glide-side
        // produce the same path. This was bug 7cb0f2b.
        $coverSide = Server::cachePath('hero.jpg', [
            'fm' => 'avif', 'w' => 1080, 'q' => 50,
            'h' => 1080, 'fit' => 'cover-50-50',
        ]);
        $cropSide = Server::cachePath('hero.jpg', [
            'fm' => 'avif', 'w' => 1080, 'q' => 50,
            'h' => 1080, 'fit' => 'crop-50-50',
        ]);
        self::assertSame($coverSide, $cropSide);
        self::assertSame('avif-1080-1080-cover-50-50-50/hero.jpg.avif', $cropSide);
    }

    public function testCachePathFallsBackToExtensionWhenFormatMissing(): void
    {
        $path = Server::cachePath('hero.png', ['w' => 1080, 'q' => 50]);
        self::assertSame('png-1080-50/hero.png.png', $path);
    }

    public function testCachePathFitWithoutHeightFallsBackToLegacy(): void
    {
        // Defensive: if fit is set but h isn't, no crop is emitted.
        $path = Server::cachePath('hero.jpg', [
            'fm' => 'avif', 'w' => 1080, 'q' => 50,
            'fit' => 'cover-50-50',
        ]);
        self::assertSame('avif-1080-50/hero.jpg.avif', $path);
    }
}
