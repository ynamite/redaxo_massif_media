<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Glide;

use League\Glide\Manipulators\Watermark;
use PHPUnit\Framework\TestCase;
use rex_path;
use Ynamite\Media\Glide\Server;
use Ynamite\Media\Source\ExternalSource;

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

    public function testMediapoolServerConfiguresWatermarksFilesystem(): void
    {
        // Glide's Watermark manipulator short-circuits when no watermarks
        // filesystem is set, silently ignoring `mark`/`markpos`/etc. The
        // mediapool itself is the watermark root, so users can write
        // `mark="logo.png"` and have it resolve to `rex_path::media() . logo.png`.
        rex_path::_setBase(sys_get_temp_dir() . '/massif_server_wmark_' . uniqid('', true));
        @mkdir(rex_path::media(), 0777, true);

        $server = Server::create();
        $watermark = self::findWatermarkManipulator($server);

        self::assertNotNull($watermark, 'Watermark manipulator missing from Glide pipeline');
        self::assertNotNull($watermark->getWatermarks(), 'watermarks filesystem not configured — mark params will be ignored');
    }

    public function testExternalServerConfiguresWatermarksFilesystem(): void
    {
        // External sources still pull watermarks from the mediapool — the
        // per-bucket cache dir holds only the fetched origin and its variants,
        // never user-uploaded assets.
        rex_path::_setBase(sys_get_temp_dir() . '/massif_server_wmark_ext_' . uniqid('', true));
        @mkdir(rex_path::media(), 0777, true);

        $external = new ExternalSource(
            url: 'https://example.com/x.jpg',
            hash: 'abc123',
            absolutePath: rex_path::addonAssets('massif_media', 'cache/_external/abc123/_origin.bin'),
            fetchedAt: 1700000000,
            etag: null,
            remoteLastModified: null,
            ttlSeconds: 86400,
        );

        $server = Server::createForExternal($external);
        $watermark = self::findWatermarkManipulator($server);

        self::assertNotNull($watermark);
        self::assertNotNull($watermark->getWatermarks());
    }

    private static function findWatermarkManipulator(\League\Glide\Server $server): ?Watermark
    {
        foreach ($server->getApi()->getManipulators() as $manipulator) {
            if ($manipulator instanceof Watermark) {
                return $manipulator;
            }
        }
        return null;
    }
}
