<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Pipeline;

use PHPUnit\Framework\TestCase;
use rex_config;
use Ynamite\Media\Config;
use Ynamite\Media\Pipeline\Placeholder;
use Ynamite\Media\Pipeline\ResolvedImage;

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
            sourcePath: 'hero.jpg',
            absolutePath: '/tmp/hero.jpg',
            intrinsicWidth: 1600,
            intrinsicHeight: 900,
            mime: 'image/jpeg',
            sourceFormat: 'jpg',
        );

        self::assertSame('', (new Placeholder())->generate($image));
    }

    public function testGenerateReturnsEmptyWhenLqipDisabledEvenIfBlurhashEnabled(): void
    {
        rex_config::set(Config::ADDON, Config::KEY_LQIP_ENABLED, 0);
        rex_config::set(Config::ADDON, Config::KEY_BLURHASH_ENABLED, 1);

        $image = new ResolvedImage(
            sourcePath: 'hero.jpg',
            absolutePath: '/tmp/hero.jpg',
            intrinsicWidth: 1600,
            intrinsicHeight: 900,
            mime: 'image/jpeg',
            sourceFormat: 'jpg',
            blurhash: 'LEHV6nWB2yk8pyo0adR*.7kCMdnj',
        );

        self::assertSame('', (new Placeholder())->generate($image));
    }

    public function testGenerateReturnsEmptyForPassthroughEvenIfLqipEnabled(): void
    {
        rex_config::set(Config::ADDON, Config::KEY_LQIP_ENABLED, 1);

        $svg = new ResolvedImage(
            sourcePath: 'logo.svg',
            absolutePath: '/tmp/logo.svg',
            intrinsicWidth: 100,
            intrinsicHeight: 100,
            mime: 'image/svg+xml',
            sourceFormat: 'svg',
        );

        self::assertSame('', (new Placeholder())->generate($svg));
    }
}
