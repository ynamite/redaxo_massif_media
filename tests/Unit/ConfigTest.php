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

    public function testLqipBlurReturnsDefaultWhenUnset(): void
    {
        self::assertSame(5, Config::lqipBlur());
    }

    public function testCheckboxBoolReadsRexConfigFormPipeFormatAsTrue(): void
    {
        // rex_config_form::addCheckboxField stores ticked checkboxes as '|<opt>|'.
        // A naive (bool)(int) cast on '|1|' returns false (PHP int-casts strings
        // that don't start with a digit to 0). Verify checkbox-backed bools
        // handle the pipe format correctly.
        rex_config::set(Config::ADDON, Config::KEY_LQIP_ENABLED, '|1|');
        rex_config::set(Config::ADDON, Config::KEY_CDN_ENABLED, '|1|');

        self::assertTrue(Config::lqipEnabled());
        self::assertTrue(Config::cdnEnabled());
    }

    public function testCheckboxBoolReadsEmptyStringAsFalse(): void
    {
        // Unticked checkbox saves as ''. Must read as false.
        rex_config::set(Config::ADDON, Config::KEY_LQIP_ENABLED, '');
        rex_config::set(Config::ADDON, Config::KEY_CDN_ENABLED, '');

        self::assertFalse(Config::lqipEnabled());
        self::assertFalse(Config::cdnEnabled());
    }

    public function testCheckboxBoolReadsIntegerDefaultsCorrectly(): void
    {
        // Config::DEFAULTS use plain ints (1/0). The fallback path must work.
        // (No rex_config::set — falls through to DEFAULTS via Config::get.)
        self::assertTrue(Config::lqipEnabled());      // DEFAULTS[KEY_LQIP_ENABLED] = 1
        self::assertFalse(Config::cdnEnabled());      // DEFAULTS[KEY_CDN_ENABLED] = 0
    }
}
