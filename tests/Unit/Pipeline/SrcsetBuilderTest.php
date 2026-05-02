<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Pipeline;

use PHPUnit\Framework\TestCase;
use Ynamite\Media\Pipeline\SrcsetBuilder;

final class SrcsetBuilderTest extends TestCase
{
    private SrcsetBuilder $builder;

    protected function setUp(): void
    {
        \rex_config::_reset();
        // Set deterministic pools for the tests; the production defaults change
        // and we don't want test brittleness from that.
        \rex_config::set('massif_media', 'device_sizes', '640,750,828,1080,1200,1920,2048,3840');
        \rex_config::set('massif_media', 'image_sizes', '16,32,48,64,96,128,256,384');
        $this->builder = new SrcsetBuilder();
    }

    public function testCapsPoolAtIntrinsicWidth(): void
    {
        $widths = $this->builder->build(1500);

        self::assertContains(1080, $widths);
        self::assertContains(1200, $widths);
        self::assertContains(1500, $widths, 'intrinsic should always be present as the top variant');
        self::assertNotContains(1920, $widths);
        self::assertNotContains(3840, $widths);
    }

    public function testReturnsAscendingUnique(): void
    {
        $widths = $this->builder->build(5000);

        self::assertSame($widths, array_values(array_unique($widths)));

        $sorted = $widths;
        sort($sorted);
        self::assertSame($sorted, $widths, 'widths should be ascending');
    }

    public function testOverridePoolReplacesDefaults(): void
    {
        // Use intrinsic = max(override) so the cap is already in the override
        // pool; otherwise the builder appends intrinsic as a top variant.
        $widths = $this->builder->build(960, [320, 640, 960]);

        self::assertSame([320, 640, 960], $widths);
    }

    public function testEffectiveMaxWidthCap(): void
    {
        // Source is 5000×4000. For a 1:1 cover crop, max useful width is 4000.
        $widths = $this->builder->build(5000, null, 4000);

        self::assertContains(3840, $widths);
        self::assertContains(4000, $widths, 'effective cap should appear as top variant');
        self::assertNotContains(5000, $widths);
        foreach ($widths as $w) {
            self::assertLessThanOrEqual(4000, $w);
        }
    }

    public function testZeroIntrinsicReturnsEmpty(): void
    {
        $widths = $this->builder->build(0);
        self::assertSame([], $widths);
    }

    public function testEffectiveMaxWidthAboveIntrinsicIsIgnored(): void
    {
        // If effectiveMaxWidth > intrinsic, the smaller intrinsic still caps.
        $widths = $this->builder->build(800, null, 5000);

        self::assertSame(800, end($widths));
    }
}
