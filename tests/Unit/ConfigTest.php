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

    public function testCanServerEncodeBaselineFormatsAlwaysTrue(): void
    {
        // jpg/jpeg/png/gif short-circuit BEFORE the driver-specific check.
        // Any sane host has at least GD with these codecs, and even an
        // exotic Imagick build that somehow lacks them would also break
        // half of REDAXO — so the unconditional true is the safer default.
        self::assertTrue(Config::canServerEncode('jpg'));
        self::assertTrue(Config::canServerEncode('jpeg'));
        self::assertTrue(Config::canServerEncode('png'));
        self::assertTrue(Config::canServerEncode('gif'));
    }

    public function testCanServerEncodeIsCaseInsensitive(): void
    {
        self::assertTrue(Config::canServerEncode('JPG'));
        self::assertTrue(Config::canServerEncode('Jpeg'));
        self::assertTrue(Config::canServerEncode('PNG'));
    }

    public function testCanServerEncodeAvifMatchesImagickQueryFormats(): void
    {
        // Verifies our detection AGREES with Imagick::queryFormats() rather
        // than asserting AVIF is/isn't available — the test environment
        // varies. Mirrors media_negotiator/lib/Helper.php:244-260's logic.
        if (!extension_loaded('imagick')) {
            self::markTestSkipped('Imagick required to verify queryFormats consistency');
        }

        $im = new \Imagick();
        $expected = in_array('AVIF', $im->queryFormats(), true);
        $im->destroy();

        self::assertSame($expected, Config::canServerEncode('avif'));
    }

    public function testCanServerEncodeWebpMatchesImagickQueryFormats(): void
    {
        if (!extension_loaded('imagick')) {
            self::markTestSkipped('Imagick required to verify queryFormats consistency');
        }

        $im = new \Imagick();
        $expected = in_array('WEBP', $im->queryFormats(), true);
        $im->destroy();

        self::assertSame($expected, Config::canServerEncode('webp'));
    }

    public function testCanServerEncodeUnknownFormatReturnsFalse(): void
    {
        // Format outside the supported set falls through both branches —
        // baseline list doesn't match, capability map doesn't carry the key.
        self::assertFalse(Config::canServerEncode('heic'));
        self::assertFalse(Config::canServerEncode('jxl'));
        self::assertFalse(Config::canServerEncode(''));
    }

    public function testCacheGenerationReturnsConfiguredValue(): void
    {
        $this->resetCacheGenerationStatic();
        rex_config::set(Config::ADDON, Config::KEY_CACHE_GENERATION, 1_700_000_000);

        self::assertSame(1_700_000_000, Config::cacheGeneration());
    }

    public function testCacheGenerationFallsBackToTimeWhenUnset(): void
    {
        // Pre-install upgrade path: the install.php seed hasn't run yet but
        // URLs are already being emitted. The accessor must NOT return 0
        // (which would suppress the `&g=` segment entirely) — fall back to
        // a per-process value so URLs at least change cohort-wise across
        // PHP-FPM workers / restarts.
        $this->resetCacheGenerationStatic();

        $before = time();
        $value = Config::cacheGeneration();
        $after = time();

        self::assertGreaterThanOrEqual($before, $value);
        self::assertLessThanOrEqual($after, $value);
    }

    public function testBumpCacheGenerationUpdatesValue(): void
    {
        $this->resetCacheGenerationStatic();
        rex_config::set(Config::ADDON, Config::KEY_CACHE_GENERATION, 1_700_000_000);
        // Prime the static cache.
        self::assertSame(1_700_000_000, Config::cacheGeneration());

        $before = time();
        Config::bumpCacheGeneration();
        $after = time();

        // bumpCacheGeneration writes time() to rex_config AND updates the
        // static cache so the same-request reads pick up the new value
        // immediately — the URL emissions later in the request need the
        // bumped token, can't wait for the next request to pick it up.
        $value = Config::cacheGeneration();
        self::assertGreaterThanOrEqual($before, $value);
        self::assertLessThanOrEqual($after, $value);
        self::assertNotSame(1_700_000_000, $value);
    }

    private function resetCacheGenerationStatic(): void
    {
        $prop = new \ReflectionProperty(Config::class, 'cacheGenerationCache');
        $prop->setValue(null, null);
    }
}
