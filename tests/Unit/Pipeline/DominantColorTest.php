<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Pipeline;

use PHPUnit\Framework\TestCase;
use rex_config;
use rex_path;
use Ynamite\Media\Config;
use Ynamite\Media\Pipeline\DominantColor;
use Ynamite\Media\Pipeline\ResolvedImage;

/**
 * Locks in the gate conditions of DominantColor::generate. The Imagick-backed
 * generation path is tested via integration in tests/Integration/ — here we
 * only verify the cheap branches: disabled, passthrough source, cache hit.
 */
final class DominantColorTest extends TestCase
{
    private string $tmpBase;

    protected function setUp(): void
    {
        $this->tmpBase = sys_get_temp_dir() . '/massif_media_dominantcolor_' . uniqid('', true);
        rex_path::_setBase($this->tmpBase);
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

    private function image(string $format = 'jpg'): ResolvedImage
    {
        return new ResolvedImage(
            sourcePath: 'hero.' . $format,
            absolutePath: $this->tmpBase . '/media/hero.' . $format,
            intrinsicWidth: 800,
            intrinsicHeight: 600,
            mime: 'image/' . ($format === 'jpg' ? 'jpeg' : $format),
            sourceFormat: $format,
            mtime: 1_700_000_000,
        );
    }

    public function testReturnsEmptyWhenColorDisabled(): void
    {
        rex_config::set(Config::ADDON, Config::KEY_COLOR_ENABLED, 0);

        self::assertSame('', (new DominantColor())->generate($this->image()));
    }

    public function testReturnsEmptyForPassthroughEvenWhenEnabled(): void
    {
        rex_config::set(Config::ADDON, Config::KEY_COLOR_ENABLED, 1);

        self::assertSame('', (new DominantColor())->generate($this->image('svg')));
        self::assertSame('', (new DominantColor())->generate($this->image('gif')));
    }

    public function testReturnsCachedHexWhenCacheFilePresent(): void
    {
        rex_config::set(Config::ADDON, Config::KEY_COLOR_ENABLED, 1);
        $image = $this->image();
        $hash = hash('xxh64', $image->sourcePath . ':' . $image->mtime . ':v1');
        $cachePath = rex_path::addonAssets(
            Config::ADDON,
            'cache/_color/' . substr($hash, 0, 2) . '/' . $hash . '.txt',
        );
        @mkdir(dirname($cachePath), 0777, true);
        file_put_contents($cachePath, '#deadbe');

        self::assertSame('#deadbe', (new DominantColor())->generate($image));
    }

    public function testReturnsEmptyWhenSourceUnreadableAndNoCache(): void
    {
        // Enabled, no cache, absolutePath points at a file that doesn't exist.
        // Imagick path bails on the is_readable() check before any allocation.
        rex_config::set(Config::ADDON, Config::KEY_COLOR_ENABLED, 1);

        self::assertSame('', (new DominantColor())->generate($this->image()));
    }
}
