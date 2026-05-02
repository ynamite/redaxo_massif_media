<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Integration;

use PHPUnit\Framework\TestCase;
use Ynamite\Media\Glide\CacheKeyBuilder;
use Ynamite\Media\Glide\Server;
use Ynamite\Media\Glide\Signature;

final class FilterPipelineTest extends TestCase
{
    private string $tmpDir;
    private string $sourceDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        \rex_config::_reset();
        \rex_config::set('massif_media', 'sign_key', 'integration-test-key');

        $this->tmpDir = sys_get_temp_dir() . '/massif_media_filters_' . uniqid('', true);
        $this->sourceDir = $this->tmpDir . '/source';
        $this->cacheDir = $this->tmpDir . '/cache';
        @mkdir($this->sourceDir, 0777, true);
        @mkdir($this->cacheDir, 0777, true);

        copy(
            __DIR__ . '/../_fixtures/landscape-800x600.jpg',
            $this->sourceDir . '/hero.jpg',
        );
    }

    protected function tearDown(): void
    {
        Server::clearActiveFilters();
        \rex_dir::delete($this->tmpDir, true);
    }

    public function testFilterParamsContributeToCachePathHash(): void
    {
        $unfilteredPath = Server::cachePath('hero.jpg', ['fm' => 'jpg', 'w' => 400, 'q' => 80]);
        $filteredPath = Server::cachePath('hero.jpg', [
            'fm' => 'jpg', 'w' => 400, 'q' => 80,
            'filters' => ['bri' => 10],
        ]);

        self::assertNotSame($unfilteredPath, $filteredPath);
        self::assertMatchesRegularExpression('@-f[a-f0-9]{8}\.jpg$@', $filteredPath);
    }

    public function testGreyscaleFilterProducesGreyscaleVariant(): void
    {
        Server::setActiveFilters(['filt' => 'greyscale']);
        try {
            $server = Server::create($this->sourceDir, $this->cacheDir);
            $rel = $server->makeImage('hero.jpg', [
                'fm' => 'jpg', 'w' => 200, 'q' => 80,
                'filt' => 'greyscale',
            ]);
        } finally {
            Server::clearActiveFilters();
        }

        $cacheFile = $this->cacheDir . '/' . $rel;
        self::assertFileExists($cacheFile);
        self::assertMatchesRegularExpression('@hero\.jpg/jpg-200-80-f[a-f0-9]{8}\.jpg$@', $rel);

        // Greyscale → R/G/B channels collapse to (near-)equal values per pixel.
        $im = imagecreatefromjpeg($cacheFile);
        $w = imagesx($im);
        $h = imagesy($im);
        $sampleCount = 0;
        $greyMatch = 0;
        for ($x = 0; $x < $w; $x += 20) {
            for ($y = 0; $y < $h; $y += 20) {
                $rgb = imagecolorat($im, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $sampleCount++;
                if (max($r, $g, $b) - min($r, $g, $b) <= 5) {
                    $greyMatch++;
                }
            }
        }
        imagedestroy($im);
        self::assertGreaterThan(0, $sampleCount);
        $matchRate = $greyMatch / $sampleCount;
        self::assertGreaterThan(0.9, $matchRate, "Expected >90% of sampled pixels to be ~grey; got " . round($matchRate * 100) . '%');
    }

    public function testTamperedFilterBlobFailsHmacVerification(): void
    {
        $key = 'integration-test-key';
        $cachePath = Server::cachePath('hero.jpg', [
            'fm' => 'jpg', 'w' => 200, 'q' => 80,
            'filters' => ['bri' => 10],
        ]);
        $filterBlob = CacheKeyBuilder::encodeFilterBlob(['bri' => 10]);
        $sig = Signature::sign($cachePath, $filterBlob, $key);

        self::assertTrue(Signature::verify($cachePath, $sig, $filterBlob, $key));

        $tamperedBlob = CacheKeyBuilder::encodeFilterBlob(['bri' => 99]);
        self::assertFalse(Signature::verify($cachePath, $sig, $tamperedBlob, $key));
    }
}
