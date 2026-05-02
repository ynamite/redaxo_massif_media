<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Integration;

use PHPUnit\Framework\TestCase;
use Ynamite\Media\Glide\Server;

final class CropPipelineTest extends TestCase
{
    private string $tmpDir;
    private string $sourceDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/massif_media_test_' . uniqid('', true);
        $this->sourceDir = $this->tmpDir . '/source';
        $this->cacheDir = $this->tmpDir . '/cache';
        @mkdir($this->sourceDir, 0777, true);
        @mkdir($this->cacheDir, 0777, true);

        // Copy fixture image into the synthetic source dir.
        copy(
            __DIR__ . '/../_fixtures/landscape-800x600.jpg',
            $this->sourceDir . '/hero.jpg',
        );
    }

    protected function tearDown(): void
    {
        \rex_dir::delete($this->tmpDir, true);
    }

    public function testNoCropProducesProportionalVariant(): void
    {
        $server = Server::create($this->sourceDir, $this->cacheDir);
        $rel = $server->makeImage('hero.jpg', ['fm' => 'jpg', 'w' => 400, 'q' => 80]);

        $cacheFile = $this->cacheDir . '/' . $rel;
        self::assertFileExists($cacheFile);
        [$w, $h] = getimagesize($cacheFile);
        self::assertSame(400, $w);
        self::assertSame(300, $h, 'no crop preserves intrinsic aspect (4:3 → 400x300)');
    }

    public function testCoverCropProducesSquareVariant(): void
    {
        $server = Server::create($this->sourceDir, $this->cacheDir);
        $rel = $server->makeImage('hero.jpg', [
            'fm' => 'jpg', 'w' => 400, 'q' => 80,
            'h' => 400, 'fit' => 'crop-50-50',
        ]);

        $cacheFile = $this->cacheDir . '/' . $rel;
        self::assertFileExists($cacheFile);
        [$w, $h] = getimagesize($cacheFile);
        self::assertSame(400, $w);
        self::assertSame(400, $h, 'cover crops the source to fill the box');
    }

    public function testStretchProducesExactlyRequestedDimensions(): void
    {
        $server = Server::create($this->sourceDir, $this->cacheDir);
        $rel = $server->makeImage('hero.jpg', [
            'fm' => 'jpg', 'w' => 200, 'q' => 80,
            'h' => 100, 'fit' => 'stretch',
        ]);

        $cacheFile = $this->cacheDir . '/' . $rel;
        self::assertFileExists($cacheFile);
        [$w, $h] = getimagesize($cacheFile);
        self::assertSame(200, $w);
        self::assertSame(100, $h);
    }

    public function testCachePathContainsCoverFitToken(): void
    {
        $server = Server::create($this->sourceDir, $this->cacheDir);
        $rel = $server->makeImage('hero.jpg', [
            'fm' => 'jpg', 'w' => 400, 'q' => 80,
            'h' => 400, 'fit' => 'crop-50-50',
        ]);

        // cachePath normalizes Glide's `crop-X-Y` → our `cover-X-Y`.
        self::assertStringContainsString('cover-50-50', $rel);
        self::assertStringNotContainsString('crop-50-50', $rel);
    }
}
