<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Glide;

use PHPUnit\Framework\TestCase;
use Ynamite\Media\Enum\Fit;
use Ynamite\Media\Glide\FitTokenBuilder;

final class FitTokenBuilderTest extends TestCase
{
    public function testBuildEmitsContainAndStretchVerbatim(): void
    {
        self::assertSame('contain', FitTokenBuilder::build(Fit::CONTAIN, '50% 50%'));
        self::assertSame('stretch', FitTokenBuilder::build(Fit::STRETCH, '50% 50%'));
    }

    public function testBuildEmitsCoverWithFocalCoords(): void
    {
        self::assertSame('cover-33-67', FitTokenBuilder::build(Fit::COVER, '33% 67%'));
    }

    public function testBuildCoverFallsBackToCenterOnMissingFocal(): void
    {
        self::assertSame('cover-50-50', FitTokenBuilder::build(Fit::COVER, null));
    }

    public function testBuildCoverRoundsDecimalCoords(): void
    {
        // Glide's Size manipulator regex (vendor/league/glide/src/Manipulators/Size.php:118)
        // rejects decimals on the first two crop coords, so build() must round to int.
        self::assertSame('cover-50-51', FitTokenBuilder::build(Fit::COVER, '50.4% 50.6%'));
    }

    public function testParseFocalToIntsWithValidPercentString(): void
    {
        self::assertSame([50, 30], FitTokenBuilder::parseFocalToInts('50% 30%'));
        self::assertSame([0, 100], FitTokenBuilder::parseFocalToInts('0% 100%'));
    }

    public function testParseFocalToIntsRoundsDecimals(): void
    {
        self::assertSame([50, 51], FitTokenBuilder::parseFocalToInts('50.4% 50.6%'));
    }

    public function testParseFocalToIntsClampsRange(): void
    {
        self::assertSame([100, 100], FitTokenBuilder::parseFocalToInts('150% 200%'));
    }

    public function testParseFocalToIntsFallsBackToCenterOnNullOrMalformed(): void
    {
        self::assertSame([50, 50], FitTokenBuilder::parseFocalToInts(null));
        self::assertSame([50, 50], FitTokenBuilder::parseFocalToInts('garbage'));
        self::assertSame([50, 50], FitTokenBuilder::parseFocalToInts(''));
    }
}
