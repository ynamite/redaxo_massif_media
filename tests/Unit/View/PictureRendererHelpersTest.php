<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\View;

use PHPUnit\Framework\TestCase;
use Ynamite\Media\View\PictureRenderer;

final class PictureRendererHelpersTest extends TestCase
{
    public function testParseFocalToIntsWithValidPercentString(): void
    {
        self::assertSame([50, 30], PictureRenderer::parseFocalToInts('50% 30%'));
        self::assertSame([0, 100], PictureRenderer::parseFocalToInts('0% 100%'));
    }

    public function testParseFocalToIntsRoundsDecimals(): void
    {
        // 50.4 rounds to 50, 50.6 rounds to 51
        self::assertSame([50, 51], PictureRenderer::parseFocalToInts('50.4% 50.6%'));
    }

    public function testParseFocalToIntsClampsRange(): void
    {
        // Regex accepts only [\d.]+%, so we test the upper clamp with values
        // that match the regex; negatives can't reach the clamp branch.
        self::assertSame([100, 100], PictureRenderer::parseFocalToInts('150% 200%'));
    }

    public function testParseFocalToIntsFallsBackToCenterOnNullOrMalformed(): void
    {
        self::assertSame([50, 50], PictureRenderer::parseFocalToInts(null));
        self::assertSame([50, 50], PictureRenderer::parseFocalToInts('garbage'));
        self::assertSame([50, 50], PictureRenderer::parseFocalToInts(''));
    }
}
