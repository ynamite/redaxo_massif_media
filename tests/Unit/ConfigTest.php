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
        // Legacy unticked-checkbox shape (older REDAXO versions stored '').
        rex_config::set(Config::ADDON, Config::KEY_LQIP_ENABLED, '');
        rex_config::set(Config::ADDON, Config::KEY_CDN_ENABLED, '');

        self::assertFalse(Config::lqipEnabled());
        self::assertFalse(Config::cdnEnabled());
    }

    public function testCheckboxBoolReadsExplicitNullAsFalse(): void
    {
        // Current REDAXO behaviour: unticked checkbox in rex_config_form
        // posts back with the field absent from $_POST; the form sets the
        // element value to null and config_form::save calls
        // rex_config::set(..., null). The previous Config::checkboxBool
        // collapsed this null to the shipped DEFAULT (1) — silently undoing
        // the user's untick. With the array_key_exists path we now correctly
        // distinguish "explicit null" from "never written" and treat the
        // first as false.
        rex_config::set(Config::ADDON, Config::KEY_LQIP_ENABLED, null);
        rex_config::set(Config::ADDON, Config::KEY_CDN_ENABLED, null);

        self::assertFalse(Config::lqipEnabled());
        self::assertFalse(Config::cdnEnabled());
    }

    public function testCheckboxBoolReadsIntegerDefaultsCorrectly(): void
    {
        // Config::DEFAULTS use plain ints (1/0). When the key has truly never
        // been written (no rex_config::set call), the DEFAULTS path must run.
        self::assertTrue(Config::lqipEnabled());      // DEFAULTS[KEY_LQIP_ENABLED] = 1
        self::assertFalse(Config::cdnEnabled());      // DEFAULTS[KEY_CDN_ENABLED] = 0
        self::assertTrue(Config::colorEnabled());     // DEFAULTS[KEY_COLOR_ENABLED] = 1
    }

    public function testCheckboxBoolDistinguishesNeverWrittenFromExplicitNull(): void
    {
        // Critical distinction: the same null read from rex_config::get must
        // produce different results depending on whether the key was ever
        // written. Demonstrated against KEY_LQIP_ENABLED which defaults on:
        //   - never written → DEFAULTS = 1 → true  (fresh-install behaviour)
        //   - explicitly set to null → false       (user actively unticked)
        self::assertTrue(Config::lqipEnabled(), 'Fresh install: LQIP defaults on.');

        rex_config::set(Config::ADDON, Config::KEY_LQIP_ENABLED, null);
        self::assertFalse(Config::lqipEnabled(), 'After untick: LQIP turns off.');
    }
}
