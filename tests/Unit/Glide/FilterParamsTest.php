<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Glide;

use PHPUnit\Framework\TestCase;
use Ynamite\Media\Glide\FilterParams;

final class FilterParamsTest extends TestCase
{
    public function testNormalizeTranslatesFriendlyNames(): void
    {
        $out = FilterParams::normalize(['brightness' => 10, 'contrast' => 5]);
        self::assertSame(['bri' => 10, 'con' => 5], $out);
    }

    public function testNormalizeAcceptsGlideShortNamesToo(): void
    {
        $out = FilterParams::normalize(['bri' => 10, 'mark' => 'logo.png']);
        self::assertSame(['bri' => 10, 'mark' => 'logo.png'], $out);
    }

    public function testNormalizeClampsBrightness(): void
    {
        self::assertSame(['bri' => 100], FilterParams::normalize(['brightness' => 200]));
        self::assertSame(['bri' => -100], FilterParams::normalize(['brightness' => -300]));
    }

    public function testNormalizeClampsGammaAsFloat(): void
    {
        $out = FilterParams::normalize(['gamma' => 15.0]);
        self::assertSame(['gam' => 9.99], $out);
    }

    public function testNormalizeDropsInvalidHex(): void
    {
        self::assertSame([], FilterParams::normalize(['bg' => 'xyz']));
        self::assertSame(['bg' => 'ffffff'], FilterParams::normalize(['bg' => 'ffffff']));
        self::assertSame(['bg' => 'ffffff'], FilterParams::normalize(['bg' => 'FFFFFF']));
    }

    public function testNormalizeDropsUnknownKeys(): void
    {
        self::assertSame(['bri' => 10], FilterParams::normalize(['brightness' => 10, 'bogus' => 'value']));
    }

    public function testValidateHexAcceptsSixHexChars(): void
    {
        self::assertSame('ff00cc', FilterParams::validateHex('ff00cc'));
        self::assertSame('FF00CC', FilterParams::validateHex('FF00CC'));
        self::assertNull(FilterParams::validateHex('xyz'));
        self::assertNull(FilterParams::validateHex('#ffffff'));
        self::assertNull(FilterParams::validateHex('fff'));
    }

    public function testClampNumericRanges(): void
    {
        self::assertSame(50, FilterParams::clamp('bri', 50));
        self::assertSame(100, FilterParams::clamp('bri', 200));
        self::assertSame(-100, FilterParams::clamp('bri', -200));
        self::assertSame(0.5, FilterParams::clamp('gam', 0.5));
        self::assertSame(0.1, FilterParams::clamp('gam', 0.0));
        self::assertSame(9.99, FilterParams::clamp('gam', 100));
    }
}
