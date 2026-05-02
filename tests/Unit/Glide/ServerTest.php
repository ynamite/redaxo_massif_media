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
        self::assertSame('hero.jpg/avif-1080-50.avif', $path);
    }

    public function testCachePathCropShape(): void
    {
        $path = Server::cachePath('hero.jpg', [
            'fm' => 'avif', 'w' => 1080, 'q' => 50,
            'h' => 1080, 'fit' => 'cover-50-50',
        ]);
        self::assertSame('hero.jpg/avif-1080-1080-cover-50-50-50.avif', $path);
    }

    public function testCachePathContainShape(): void
    {
        $path = Server::cachePath('hero.jpg', [
            'fm' => 'webp', 'w' => 800, 'q' => 75,
            'h' => 600, 'fit' => 'contain',
        ]);
        self::assertSame('hero.jpg/webp-800-600-contain-75.webp', $path);
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
        self::assertSame('hero.jpg/avif-1080-1080-cover-50-50-50.avif', $cropSide);
    }

    public function testCachePathFallsBackToExtensionWhenFormatMissing(): void
    {
        $path = Server::cachePath('hero.png', ['w' => 1080, 'q' => 50]);
        self::assertSame('hero.png/png-1080-50.png', $path);
    }

    public function testCachePathFitWithoutHeightFallsBackToLegacy(): void
    {
        // Defensive: if fit is set but h isn't, no crop is emitted.
        $path = Server::cachePath('hero.jpg', [
            'fm' => 'avif', 'w' => 1080, 'q' => 50,
            'fit' => 'cover-50-50',
        ]);
        self::assertSame('hero.jpg/avif-1080-50.avif', $path);
    }

    public function testCachePathPreservesSourceSubdirs(): void
    {
        $path = Server::cachePath('gallery/2024/atelier.jpg', [
            'fm' => 'avif', 'w' => 1920, 'q' => 50,
            'h' => 1920, 'fit' => 'cover-30-70',
        ]);
        self::assertSame('gallery/2024/atelier.jpg/avif-1920-1920-cover-30-70-50.avif', $path);
    }

    public function testCachePathWithFiltersAppendsHash(): void
    {
        $path = Server::cachePath('hero.jpg', [
            'fm' => 'jpg', 'w' => 800, 'q' => 80,
            'filters' => ['bri' => 10, 'sharp' => 20],
        ]);
        self::assertMatchesRegularExpression('@^hero\.jpg/jpg-800-80-f[a-f0-9]{8}\.jpg$@', $path);
    }

    public function testCachePathFilterHashIsDeterministic(): void
    {
        // Same filters in different insertion order must produce the same hash.
        $a = Server::cachePath('hero.jpg', [
            'fm' => 'jpg', 'w' => 800, 'q' => 80,
            'filters' => ['bri' => 10, 'sharp' => 20, 'con' => 5],
        ]);
        $b = Server::cachePath('hero.jpg', [
            'fm' => 'jpg', 'w' => 800, 'q' => 80,
            'filters' => ['con' => 5, 'sharp' => 20, 'bri' => 10],
        ]);
        self::assertSame($a, $b);
    }

    public function testCachePathCropAndFilters(): void
    {
        $path = Server::cachePath('hero.jpg', [
            'fm' => 'avif', 'w' => 1080, 'q' => 50,
            'h' => 1080, 'fit' => 'cover-50-50',
            'filters' => ['filt' => 'sepia'],
        ]);
        self::assertMatchesRegularExpression(
            '@^hero\.jpg/avif-1080-1080-cover-50-50-50-f[a-f0-9]{8}\.avif$@',
            $path,
        );
    }
}
