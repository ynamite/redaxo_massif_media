<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit;

use PHPUnit\Framework\TestCase;
use rex_config;
use Ynamite\Media\Config;

final class ConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        rex_config::_reset();
    }

    public function testBlurhashComponentsXReturnsDefaultWhenUnset(): void
    {
        self::assertSame(4, Config::blurhashComponentsX());
    }

    public function testBlurhashComponentsYReturnsDefaultWhenUnset(): void
    {
        self::assertSame(3, Config::blurhashComponentsY());
    }

    public function testBlurhashComponentsRoundtrip(): void
    {
        rex_config::set(Config::ADDON, Config::KEY_BLURHASH_COMPONENTS_X, 7);
        rex_config::set(Config::ADDON, Config::KEY_BLURHASH_COMPONENTS_Y, 5);

        self::assertSame(7, Config::blurhashComponentsX());
        self::assertSame(5, Config::blurhashComponentsY());
    }

    public function testBlurhashComponentsClampToUpperBound(): void
    {
        rex_config::set(Config::ADDON, Config::KEY_BLURHASH_COMPONENTS_X, 99);
        rex_config::set(Config::ADDON, Config::KEY_BLURHASH_COMPONENTS_Y, 42);

        self::assertSame(9, Config::blurhashComponentsX());
        self::assertSame(9, Config::blurhashComponentsY());
    }

    public function testBlurhashComponentsClampToLowerBound(): void
    {
        rex_config::set(Config::ADDON, Config::KEY_BLURHASH_COMPONENTS_X, 0);
        rex_config::set(Config::ADDON, Config::KEY_BLURHASH_COMPONENTS_Y, -3);

        self::assertSame(1, Config::blurhashComponentsX());
        self::assertSame(1, Config::blurhashComponentsY());
    }
}
