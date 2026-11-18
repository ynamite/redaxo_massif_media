<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Pipeline;

use PHPUnit\Framework\TestCase;
use Ynamite\Media\Pipeline\MetadataReader;

final class MetadataReaderHelpersTest extends TestCase
{
    private MetadataReader $reader;

    protected function setUp(): void
    {
        $this->reader = new MetadataReader();
    }

    public function testNormalizeFocalAcceptsPercentString(): void
    {
        self::assertSame('50% 30%', $this->reader->normalizeFocal('50% 30%'));
    }

    public function testNormalizeFocalAcceptsCommaSeparatedPercents(): void
    {
        self::assertSame('50% 30%', $this->reader->normalizeFocal('50,30'));
    }

    public function testNormalizeFocalAcceptsCommaSeparatedFloats(): void
    {
        self::assertSame('50% 30%', $this->reader->normalizeFocal('0.5,0.3'));
    }

    public function testNormalizeFocalAcceptsJsonShape(): void
    {
        self::assertSame('40% 60%', $this->reader->normalizeFocal('{"x":0.4,"y":0.6}'));
    }

    public function testNormalizeFocalRejectsMalformed(): void
    {
        self::assertNull($this->reader->normalizeFocal('not a focal point'));
        self::assertNull($this->reader->normalizeFocal(''));
    }

    public function testFormatFocalClampsToValidRange(): void
    {
        self::assertSame('100% 0%', $this->reader->formatFocal(150.0, -10.0));
        self::assertSame('50% 50%', $this->reader->formatFocal(0.5, 0.5)); // 0..1 input scaled to %
    }

    public function testFormatFocalIgnoresNumericLocale(): void
    {
        $previous = setlocale(LC_NUMERIC, '0');
        if (setlocale(LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'fr_FR.UTF-8') === false) {
            self::markTestSkipped('No comma-decimal locale installed.');
        }
        try {
            self::assertSame('49.4% 18%', $this->reader->formatFocal(49.4, 18.0));
            self::assertSame('49.4% 18%', $this->reader->formatFocal(0.494, 0.18));
        } finally {
            setlocale(LC_NUMERIC, $previous);
        }
    }
}
