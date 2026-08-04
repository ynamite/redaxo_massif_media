<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Pipeline;

use PHPUnit\Framework\TestCase;
use rex_config;
use Ynamite\Media\Config;
use Ynamite\Media\Pipeline\Placeholder;
use Ynamite\Media\Pipeline\ResolvedImage;
use Ynamite\Media\Source\MediapoolSource;

final class PlaceholderTest extends TestCase
{
    protected function tearDown(): void
    {
        rex_config::_reset();
    }

    public function testGenerateReturnsEmptyWhenLqipDisabled(): void
    {
        rex_config::set(Config::ADDON, Config::KEY_LQIP_ENABLED, 0);

        $image = new ResolvedImage(
            source: new MediapoolSource(filename: 'hero.jpg', absolutePath: '/tmp/hero.jpg', mtime: 0),
            intrinsicWidth: 1600,
            intrinsicHeight: 900,
            mime: 'image/jpeg',
            sourceFormat: 'jpg',
        );

        self::assertSame('', (new Placeholder())->generate($image));
    }

    public function testGenerateReturnsEmptyForPassthroughEvenIfLqipEnabled(): void
    {
        rex_config::set(Config::ADDON, Config::KEY_LQIP_ENABLED, 1);

        $svg = new ResolvedImage(
            source: new MediapoolSource(filename: 'logo.svg', absolutePath: '/tmp/logo.svg', mtime: 0),
            intrinsicWidth: 100,
            intrinsicHeight: 100,
            mime: 'image/svg+xml',
            sourceFormat: 'svg',
        );

        self::assertSame('', (new Placeholder())->generate($svg));
    }

    public function testCachePathIncludesLqipConfig(): void
    {
        // The key must move when LQIP config changes — that's what makes it
        // safe for `_lqip/` to survive the generic CACHE_DELETED wipe.
        $source = new MediapoolSource(filename: 'hero.jpg', absolutePath: '/tmp/hero.jpg', mtime: 123);

        rex_config::set(Config::ADDON, Config::KEY_LQIP_WIDTH, 32);
        $a = Placeholder::cachePathFor($source);
        self::assertSame($a, Placeholder::cachePathFor($source), 'stable for unchanged config');

        rex_config::set(Config::ADDON, Config::KEY_LQIP_WIDTH, 64);
        self::assertNotSame($a, Placeholder::cachePathFor($source), 'config change must move the cache path');
    }
}
