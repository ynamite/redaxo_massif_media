<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Enum;

use PHPUnit\Framework\TestCase;
use Ynamite\Media\Enum\Fit;

final class FitTest extends TestCase
{
    public function testFromValidStrings(): void
    {
        self::assertSame(Fit::COVER, Fit::from('cover'));
        self::assertSame(Fit::CONTAIN, Fit::from('contain'));
        self::assertSame(Fit::STRETCH, Fit::from('stretch'));
        self::assertSame(Fit::NONE, Fit::from('none'));
    }

    public function testFromInvalidThrowsValueError(): void
    {
        $this->expectException(\ValueError::class);
        Fit::from('bogus');
    }

    public function testTryFromInvalidReturnsNull(): void
    {
        self::assertNull(Fit::tryFrom('bogus'));
    }
}
