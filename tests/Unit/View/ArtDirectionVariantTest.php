<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\View;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ynamite\Media\Enum\Fit;
use Ynamite\Media\View\ArtDirectionVariant;

/**
 * Locks the array-form constructor (`ArtDirectionVariant::fromArray`) — the
 * shape that flows through `ImageBuilder->art([...])` and the cached PHP
 * emitted by `RexPic` for slice content. Unknown keys silently drop, missing
 * required keys throw, ratio strings parse to floats, fit strings hydrate to
 * the enum.
 */
final class ArtDirectionVariantTest extends TestCase
{
    public function testFromArrayPopulatesAllKnownFields(): void
    {
        $v = ArtDirectionVariant::fromArray([
            'media' => '(max-width: 600px)',
            'src' => 'hero-mobile.jpg',
            'width' => 640,
            'height' => 480,
            'ratio' => '4:3',
            'focal' => '50% 30%',
            'fit' => 'cover',
            'filters' => ['blur' => 5],
        ]);

        self::assertSame('(max-width: 600px)', $v->media);
        self::assertSame('hero-mobile.jpg', $v->src);
        self::assertSame(640, $v->width);
        self::assertSame(480, $v->height);
        self::assertSame(4 / 3, $v->ratio);
        self::assertSame('50% 30%', $v->focal);
        self::assertSame(Fit::COVER, $v->fit);
        self::assertSame(['blur' => 5], $v->filterParams);
    }

    public function testMissingMediaThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ArtDirectionVariant::fromArray(['src' => 'foo.jpg']);
    }

    public function testEmptyMediaThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ArtDirectionVariant::fromArray(['media' => '   ', 'src' => 'foo.jpg']);
    }

    public function testMissingSrcThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ArtDirectionVariant::fromArray(['media' => '(max-width: 600px)']);
    }

    public function testInvalidSrcTypeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ArtDirectionVariant::fromArray(['media' => '(max-width: 600px)', 'src' => 123]);
    }

    public function testRatioAcceptsColonAndSlash(): void
    {
        $colon = ArtDirectionVariant::fromArray(['media' => 'm', 'src' => 's', 'ratio' => '16:9']);
        $slash = ArtDirectionVariant::fromArray(['media' => 'm', 'src' => 's', 'ratio' => '16/9']);
        self::assertSame($colon->ratio, $slash->ratio);
        self::assertEqualsWithDelta(16 / 9, $colon->ratio, 0.0001);
    }

    public function testRatioAcceptsBareFloat(): void
    {
        $v = ArtDirectionVariant::fromArray(['media' => 'm', 'src' => 's', 'ratio' => '1.5']);
        self::assertSame(1.5, $v->ratio);
    }

    public function testInvalidRatioFallsBackToNull(): void
    {
        $v = ArtDirectionVariant::fromArray(['media' => 'm', 'src' => 's', 'ratio' => 'not-a-ratio']);
        self::assertNull($v->ratio);
    }

    public function testZeroOrNegativeWidthsCoerceToNull(): void
    {
        $v = ArtDirectionVariant::fromArray([
            'media' => 'm',
            'src' => 's',
            'width' => 0,
            'height' => -100,
        ]);
        self::assertNull($v->width);
        self::assertNull($v->height);
    }

    public function testFitStringHydratesEnum(): void
    {
        $v = ArtDirectionVariant::fromArray(['media' => 'm', 'src' => 's', 'fit' => 'contain']);
        self::assertSame(Fit::CONTAIN, $v->fit);
    }

    public function testFitInvalidStringFallsBackToNull(): void
    {
        $v = ArtDirectionVariant::fromArray(['media' => 'm', 'src' => 's', 'fit' => 'bogus']);
        self::assertNull($v->fit);
    }

    public function testFitEnumPassesThrough(): void
    {
        $v = ArtDirectionVariant::fromArray(['media' => 'm', 'src' => 's', 'fit' => Fit::STRETCH]);
        self::assertSame(Fit::STRETCH, $v->fit);
    }

    public function testFilterParamsSkipNormalizationWhenAlreadyKeyed(): void
    {
        // The `filterParams` shape (Glide-keyed, pre-normalized) skips
        // FilterParams::normalize and passes straight through. Used by
        // RexPic so cached PHP can keep variants byte-stable.
        $v = ArtDirectionVariant::fromArray([
            'media' => 'm',
            'src' => 's',
            'filterParams' => ['blur' => 8, 'bri' => -10],
        ]);
        self::assertSame(['blur' => 8, 'bri' => -10], $v->filterParams);
    }

    public function testUnknownKeysAreSilentlyDropped(): void
    {
        // Friendly contract — an editor pasting a stray key shouldn't blow up.
        $v = ArtDirectionVariant::fromArray([
            'media' => 'm',
            'src' => 's',
            'badkey' => 'whatever',
            'src2' => 'unused',
        ]);
        self::assertSame('m', $v->media);
        self::assertSame('s', $v->src);
    }
}
