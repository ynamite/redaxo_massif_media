<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Integration;

use PHPUnit\Framework\TestCase;
use rex_config;
use rex_file;
use rex_path;
use Ynamite\Media\Config;
use Ynamite\Media\Pipeline\DominantColor;
use Ynamite\Media\Pipeline\RenderBudget;
use Ynamite\Media\Pipeline\ResolvedImage;
use Ynamite\Media\Source\MediapoolSource;

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
            source: new MediapoolSource(
                filename: 'landscape-800x600.jpg',
                absolutePath: $this->fixturesDir . '/landscape-800x600.jpg',
                mtime: 1_700_000_000,
            ),
            intrinsicWidth: 800,
            intrinsicHeight: 600,
            mime: 'image/jpeg',
            sourceFormat: 'jpg',
        );

        $hex = (new DominantColor())->generate($image);

        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $hex);
        $this->assertChannelsClose($hex, '#3366aa', tolerance: 8);
    }

    public function testCacheHitOnSecondCall(): void
    {
        $image = new ResolvedImage(
            source: new MediapoolSource(
                filename: 'portrait-600x800.jpg',
                absolutePath: $this->fixturesDir . '/portrait-600x800.jpg',
                mtime: 1_700_000_000,
            ),
            intrinsicWidth: 600,
            intrinsicHeight: 800,
            mime: 'image/jpeg',
            sourceFormat: 'jpg',
        );

        $first = (new DominantColor())->generate($image);
        $cachePath = DominantColor::cachePathFor($image->source);
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

    public function testMissReturnsEmptyAndWritesNothingWhenBudgetSpent(): void
    {
        $image = $this->landscapeImage();
        RenderBudget::spend(RenderBudget::BUDGET_MS);

        self::assertSame('', (new DominantColor())->generate($image));
        self::assertFileDoesNotExist(DominantColor::cachePathFor($image->source));
    }

    public function testCacheHitIsServedWhenBudgetSpent(): void
    {
        $image = $this->landscapeImage();
        rex_file::put(DominantColor::cachePathFor($image->source), '#112233');
        RenderBudget::spend(RenderBudget::BUDGET_MS);

        self::assertSame('#112233', (new DominantColor())->generate($image));
    }

    public function testAnimatedGifGetsColorOfFirstFrame(): void
    {
        // animated-3frame.gif: frames #ff0000, #00ff00, #0000ff.
        $hex = (new DominantColor())->generate($this->fixture('animated-3frame.gif', 'gif', animated: true));

        $this->assertChannelsClose($hex, '#ff0000', tolerance: 8);
    }

    public function testAnimatedWebpGetsColorOfFirstFrame(): void
    {
        $hex = (new DominantColor())->generate($this->fixture('animated-3frame.webp', 'webp', animated: true));

        $this->assertChannelsClose($hex, '#ff0000', tolerance: 16);
    }

    public function testStaticGifGetsColor(): void
    {
        $hex = (new DominantColor())->generate($this->fixture('tiny-32x32.gif', 'gif'));

        $this->assertChannelsClose($hex, '#aaaa33', tolerance: 8);
    }

    public function testSvgGetsNoColor(): void
    {
        $image = $this->fixture('vector.svg', 'svg');

        self::assertSame('', (new DominantColor())->generate($image));
        self::assertFileDoesNotExist(DominantColor::cachePathFor($image->source));
    }

    public function testTransparentImageGetsNoColorAndIsNotDecodedAgain(): void
    {
        // background-color stays visible behind transparent pixels after load.
        $path = $this->tmpBase . '/transparent.png';
        @mkdir($this->tmpBase, 0777, true);
        $im = new \Imagick();
        $im->newImage(200, 100, new \ImagickPixel('transparent'));
        $draw = new \ImagickDraw();
        $draw->setFillColor(new \ImagickPixel('#3366aa'));
        $draw->rectangle(0, 0, 99, 99);
        $im->drawImage($draw);
        $im->setImageFormat('png');
        $im->writeImage($path);
        $im->clear();
        $image = new ResolvedImage(
            source: new MediapoolSource(filename: 'transparent.png', absolutePath: $path, mtime: 1_700_000_000),
            intrinsicWidth: 200,
            intrinsicHeight: 100,
            mime: 'image/png',
            sourceFormat: 'png',
        );

        self::assertSame('', (new DominantColor())->generate($image));
        // Remembered, so the next render does not decode the file again.
        self::assertSame('transparent', file_get_contents(DominantColor::cachePathFor($image->source)));
        RenderBudget::spend(RenderBudget::BUDGET_MS);
        self::assertSame('', (new DominantColor())->generate($image));
    }

    private function fixture(string $filename, string $format, bool $animated = false): ResolvedImage
    {
        return new ResolvedImage(
            source: new MediapoolSource(
                filename: $filename,
                absolutePath: $this->fixturesDir . '/' . $filename,
                mtime: 1_700_000_000,
            ),
            intrinsicWidth: 64,
            intrinsicHeight: 64,
            mime: 'image/' . $format,
            sourceFormat: $format,
            isAnimated: $animated,
        );
    }

    private function landscapeImage(): ResolvedImage
    {
        return new ResolvedImage(
            source: new MediapoolSource(
                filename: 'landscape-800x600.jpg',
                absolutePath: $this->fixturesDir . '/landscape-800x600.jpg',
                mtime: 1_700_000_000,
            ),
            intrinsicWidth: 800,
            intrinsicHeight: 600,
            mime: 'image/jpeg',
            sourceFormat: 'jpg',
        );
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
