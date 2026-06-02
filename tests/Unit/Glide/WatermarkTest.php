<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Glide;

use Imagick;
use ImagickPixel;
use Intervention\Image\Interfaces\ImageInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Ynamite\Media\Glide\Watermark;

/**
 * Unit coverage for the high-quality watermark manipulator's pure sizing /
 * placement logic. The Imagick compositing itself is exercised end-to-end in
 * {@see \Tests\Massif\Media\Integration\WatermarkPipelineTest}; here we pin
 * down the maths that decides target dimensions and top-left offsets, plus
 * the two things stock Glide gets wrong and we fix:
 *
 *   - `marks` (relative size) must survive {@see Watermark::getApiParams()}
 *     (stock omits it, so BaseManipulator::setParams strips it).
 *   - `markfit=cover` / `markfit=crop` must fill the box (stock's getFit()
 *     whitelist returns null for `cover`, degrading it to contain).
 */
final class WatermarkTest extends TestCase
{
    public function testGetApiParamsAddsMarksToParentSurface(): void
    {
        $params = (new Watermark(null))->getApiParams();

        // The whole point of the override: `marks` is on the surface so
        // BaseManipulator::setParams() doesn't filter it out before run().
        self::assertContains('marks', $params);

        // Parent params must still be present — we extend, not replace.
        foreach (['mark', 'markw', 'markh', 'markpos', 'markpad', 'markfit', 'markalpha'] as $expected) {
            self::assertContains($expected, $params, "lost parent param `{$expected}`");
        }
    }

    /**
     * @return array<string, array{mixed, ?float}>
     */
    public static function relativeSizeProvider(): array
    {
        return [
            'unset → null'        => [null, null],
            'mid-range'           => ['0.25', 0.25],
            'upper boundary 1.0'  => ['1.0', 1.0],
            'exactly 1'           => ['1', 1.0],
            'zero rejected'       => ['0', null],
            'negative rejected'   => ['-0.5', null],
            'above 1 rejected'    => ['1.5', null],
            'non-numeric'         => ['abc', null],
        ];
    }

    #[DataProvider('relativeSizeProvider')]
    public function testGetRelativeSizeValidation(mixed $marks, ?float $expected): void
    {
        $wm = new Watermark(null);
        if ($marks !== null) {
            $wm->setParams(['marks' => $marks]);
        }

        $method = new ReflectionMethod($wm, 'getRelativeSize');
        $method->setAccessible(true);

        self::assertSame($expected, $method->invoke($wm));
    }

    public function testComputeMarkSizeHonoursRelativeMarks(): void
    {
        $this->requireImagick();
        // marks=0.25 against an 800px source → mark width 200, height
        // auto-scaled from the mark's 2:1 aspect. This is the stock no-op
        // we fixed: stock never reads `marks` at all.
        $size = $this->computeMarkSize(
            $this->watermark(['marks' => '0.25']),
            $this->image(800, 600),
            $this->mark(400, 200),
        );
        self::assertSame([200, 100], $size);
    }

    public function testComputeMarkSizePixelWidthOnlyPreservesAspect(): void
    {
        $this->requireImagick();
        $size = $this->computeMarkSize(
            $this->watermark(['markw' => 100]),
            $this->image(800, 600),
            $this->mark(400, 200),
        );
        self::assertSame([100, 50], $size);
    }

    public function testComputeMarkSizePercentWidthIsRelativeToSource(): void
    {
        $this->requireImagick();
        // `20w` → 20% of the (already-resized) source width.
        $size = $this->computeMarkSize(
            $this->watermark(['markw' => '20w']),
            $this->image(1000, 800),
            $this->mark(400, 200),
        );
        self::assertSame([200, 100], $size);
    }

    public function testComputeMarkSizeBothDimsContainFitsInsideBox(): void
    {
        $this->requireImagick();
        // No markfit → contain (min scale). 2:1 mark into a 300×300 box.
        $size = $this->computeMarkSize(
            $this->watermark(['markw' => 300, 'markh' => 300]),
            $this->image(800, 600),
            $this->mark(400, 200),
        );
        self::assertSame([300, 150], $size);
    }

    public function testComputeMarkSizeBothDimsCoverFillsBox(): void
    {
        $this->requireImagick();
        // markfit=cover must fill the box (max scale). This is the bug:
        // stock's getFit() never returns `cover`, so it silently degraded
        // to contain → [300, 150]. We expect the fill result.
        $size = $this->computeMarkSize(
            $this->watermark(['markw' => 300, 'markh' => 300, 'markfit' => 'cover']),
            $this->image(800, 600),
            $this->mark(400, 200),
        );
        self::assertSame([600, 300], $size);
    }

    public function testComputeMarkSizeBothDimsCropTokenIsTreatedAsCover(): void
    {
        $this->requireImagick();
        // Glide's `crop*` vocabulary maps to cover for back-compat.
        $size = $this->computeMarkSize(
            $this->watermark(['markw' => 300, 'markh' => 300, 'markfit' => 'crop']),
            $this->image(800, 600),
            $this->mark(400, 200),
        );
        self::assertSame([600, 300], $size);
    }

    public function testComputeMarkSizeBothDimsStretchIgnoresAspect(): void
    {
        $this->requireImagick();
        $size = $this->computeMarkSize(
            $this->watermark(['markw' => 300, 'markh' => 300, 'markfit' => 'stretch']),
            $this->image(800, 600),
            $this->mark(400, 200),
        );
        self::assertSame([300, 300], $size);
    }

    public function testComputePlacementBottomRightWithPadding(): void
    {
        $this->requireImagick();
        $xy = $this->computePlacement(
            $this->watermark(['markpos' => 'bottom-right', 'markpad' => 20]),
            $this->image(800, 600),
            $this->mark(100, 50),
        );
        self::assertSame([680, 530], $xy);
    }

    public function testComputePlacementTopLeftWithPadding(): void
    {
        $this->requireImagick();
        $xy = $this->computePlacement(
            $this->watermark(['markpos' => 'top-left', 'markpad' => 10]),
            $this->image(800, 600),
            $this->mark(100, 50),
        );
        self::assertSame([10, 10], $xy);
    }

    public function testComputePlacementCentersOnBothAxes(): void
    {
        $this->requireImagick();
        $xy = $this->computePlacement(
            $this->watermark(['markpos' => 'center']),
            $this->image(800, 600),
            $this->mark(100, 50),
        );
        self::assertSame([350, 275], $xy);
    }

    public function testComputePlacementDefaultsToBottomRight(): void
    {
        $this->requireImagick();
        // No markpos → inherited getPosition() defaults to bottom-right.
        $xy = $this->computePlacement(
            $this->watermark([]),
            $this->image(800, 600),
            $this->mark(100, 50),
        );
        self::assertSame([700, 550], $xy);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function requireImagick(): void
    {
        if (!extension_loaded('imagick')) {
            self::markTestSkipped('Watermark sizing maths operates on an Imagick mark instance.');
        }
    }

    /**
     * @param array<string, scalar> $params
     */
    private function watermark(array $params): Watermark
    {
        $wm = new Watermark(null);
        $wm->setParams($params);
        return $wm;
    }

    private function image(int $w, int $h): ImageInterface
    {
        $stub = $this->createStub(ImageInterface::class);
        $stub->method('width')->willReturn($w);
        $stub->method('height')->willReturn($h);
        return $stub;
    }

    private function mark(int $w, int $h): Imagick
    {
        $img = new Imagick();
        $img->newImage($w, $h, new ImagickPixel('rgba(255,0,0,1)'), 'png');
        $img->setImageFormat('png');
        return $img;
    }

    /**
     * @return array{int, int}
     */
    private function computeMarkSize(Watermark $wm, ImageInterface $image, Imagick $mark): array
    {
        $method = new ReflectionMethod($wm, 'computeMarkSize');
        $method->setAccessible(true);
        return $method->invoke($wm, $image, $mark);
    }

    /**
     * @return array{int, int}
     */
    private function computePlacement(Watermark $wm, ImageInterface $image, Imagick $mark): array
    {
        $method = new ReflectionMethod($wm, 'computePlacement');
        $method->setAccessible(true);
        return $method->invoke($wm, $image, $mark);
    }
}
