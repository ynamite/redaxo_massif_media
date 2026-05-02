<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Builder;

use PHPUnit\Framework\TestCase;
use Ynamite\Media\Builder\ImageBuilder;

final class ImageBuilderTest extends TestCase
{
    private function buildAndExtractFilters(callable $configure): array
    {
        $b = new ImageBuilder('test.jpg');
        $configure($b);

        $reflection = new \ReflectionProperty($b, 'filterParams');
        return $reflection->getValue($b);
    }

    public function testBrightnessClampsRange(): void
    {
        $f = $this->buildAndExtractFilters(fn (ImageBuilder $b) => $b->brightness(200));
        self::assertSame(['bri' => 100], $f);
    }

    public function testContrastClampsRange(): void
    {
        $f = $this->buildAndExtractFilters(fn (ImageBuilder $b) => $b->contrast(-200));
        self::assertSame(['con' => -100], $f);
    }

    public function testGammaClampsAsFloat(): void
    {
        $f = $this->buildAndExtractFilters(fn (ImageBuilder $b) => $b->gamma(15.0));
        self::assertSame(['gam' => 9.99], $f);
    }

    public function testSharpenInRange(): void
    {
        $f = $this->buildAndExtractFilters(fn (ImageBuilder $b) => $b->sharpen(20));
        self::assertSame(['sharp' => 20], $f);
    }

    public function testFilterPresetPassthrough(): void
    {
        $f = $this->buildAndExtractFilters(fn (ImageBuilder $b) => $b->filter('sepia'));
        self::assertSame(['filt' => 'sepia'], $f);
    }

    public function testBgValidatesHex(): void
    {
        $valid = $this->buildAndExtractFilters(fn (ImageBuilder $b) => $b->bg('ff00cc'));
        self::assertSame(['bg' => 'ff00cc'], $valid);

        $invalid = $this->buildAndExtractFilters(fn (ImageBuilder $b) => $b->bg('xyz'));
        self::assertSame([], $invalid);
    }

    public function testBorderComposesString(): void
    {
        $f = $this->buildAndExtractFilters(fn (ImageBuilder $b) => $b->border(2, 'ff0000', 'expand'));
        self::assertSame(['border' => '2,ff0000,expand'], $f);
    }

    public function testFlipPassthrough(): void
    {
        $f = $this->buildAndExtractFilters(fn (ImageBuilder $b) => $b->flip('h'));
        self::assertSame(['flip' => 'h'], $f);
    }

    public function testWatermarkComposesAllSubParams(): void
    {
        $f = $this->buildAndExtractFilters(fn (ImageBuilder $b) => $b->watermark(
            src: 'logo.png',
            size: 0.25,
            position: 'bottom-right',
            padding: 20,
            alpha: 70,
        ));

        self::assertSame('logo.png', $f['mark']);
        self::assertSame(0.25, $f['marks']);
        self::assertSame('bottom-right', $f['markpos']);
        self::assertSame(20, $f['markpad']);
        self::assertSame(70, $f['markalpha']);
    }

    public function testFiltersBulkApplier(): void
    {
        $f = $this->buildAndExtractFilters(fn (ImageBuilder $b) => $b->filters([
            'brightness' => 10,
            'sharpen' => 20,
            'bogus' => 'value',
        ]));
        self::assertSame(['bri' => 10, 'sharp' => 20], $f);
    }

    public function testChainingMergesFilters(): void
    {
        $f = $this->buildAndExtractFilters(fn (ImageBuilder $b) =>
            $b->brightness(10)->sharpen(20)->filter('sepia')
        );
        self::assertSame(['bri' => 10, 'sharp' => 20, 'filt' => 'sepia'], $f);
    }
}
