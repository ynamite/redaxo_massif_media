<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Integration;

use PHPUnit\Framework\TestCase;
use rex_config;
use rex_path;
use Ynamite\Media\Config;
use Ynamite\Media\Pipeline\DominantColor;
use Ynamite\Media\Pipeline\ResolvedImage;

/**
 * Exercises the real Imagick path of DominantColor against fixture images
 * that are known solid colours. Skipped when Imagick isn't available so the
 * suite still passes on GD-only environments.
 */
final class DominantColorTest extends TestCase
{
    private string $tmpBase;
    private string $fixturesDir;

    protected function setUp(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('DominantColor requires Imagick.');
        }
        $this->tmpBase = sys_get_temp_dir() . '/massif_media_dc_int_' . uniqid('', true);
        $this->fixturesDir = __DIR__ . '/../_fixtures';
        rex_path::_setBase($this->tmpBase);
        rex_config::set(Config::ADDON, Config::KEY_COLOR_ENABLED, 1);
    }

    protected function tearDown(): void
    {
        rex_config::_reset();
        if (is_dir($this->tmpBase)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmpBase, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($items as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->tmpBase);
        }
    }

    public function testComputesDominantColorForLandscapeFixture(): void
    {
        // landscape-800x600.jpg is solid #3366aa. After JPEG quantization +
        // quantizeImage(1) the result is very close but not bit-exact; allow
        // ±8 per channel slack.
        $image = new ResolvedImage(
            sourcePath: 'landscape-800x600.jpg',
            absolutePath: $this->fixturesDir . '/landscape-800x600.jpg',
            intrinsicWidth: 800,
            intrinsicHeight: 600,
            mime: 'image/jpeg',
            sourceFormat: 'jpg',
            mtime: 1_700_000_000,
        );

        $hex = (new DominantColor())->generate($image);

        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $hex);
        $this->assertChannelsClose($hex, '#3366aa', tolerance: 8);
    }

    public function testCacheHitOnSecondCall(): void
    {
        $image = new ResolvedImage(
            sourcePath: 'portrait-600x800.jpg',
            absolutePath: $this->fixturesDir . '/portrait-600x800.jpg',
            intrinsicWidth: 600,
            intrinsicHeight: 800,
            mime: 'image/jpeg',
            sourceFormat: 'jpg',
            mtime: 1_700_000_000,
        );

        $first = (new DominantColor())->generate($image);
        $hash = hash('xxh64', $image->sourcePath . ':' . $image->mtime . ':v1');
        $cachePath = rex_path::addonAssets(
            Config::ADDON,
            'cache/_color/' . substr($hash, 0, 2) . '/' . $hash . '.txt',
        );
        self::assertFileExists($cachePath, 'First call should have written the cache file.');

        // Second call returns the same value — and since we don't re-run
        // Imagick, the file mtime should stay constant.
        clearstatcache();
        $mtime1 = filemtime($cachePath);
        usleep(10_000);
        $second = (new DominantColor())->generate($image);
        clearstatcache();
        $mtime2 = filemtime($cachePath);

        self::assertSame($first, $second);
        self::assertSame($mtime1, $mtime2, 'Cache hit should not rewrite the file.');
    }

    private function assertChannelsClose(string $actual, string $expected, int $tolerance): void
    {
        $a = sscanf($actual, '#%02x%02x%02x');
        $e = sscanf($expected, '#%02x%02x%02x');
        self::assertNotNull($a, "Could not parse $actual");
        self::assertNotNull($e, "Could not parse $expected");
        for ($i = 0; $i < 3; $i++) {
            self::assertLessThanOrEqual(
                $tolerance,
                abs($a[$i] - $e[$i]),
                sprintf('Channel %d: actual %d differs from expected %d by more than ±%d', $i, $a[$i], $e[$i], $tolerance),
            );
        }
    }
}
