<?php

declare(strict_types=1);

namespace Ynamite\Media\Pipeline;

use Ynamite\Media\Enum\Fit;
use Ynamite\Media\Glide\FitTokenBuilder;

/**
 * Captures the resolved render state derived from caller inputs:
 * effective ratio, effective fit, fit token, width-pool cap, and the
 * concrete width pool. Built once per render entry; used by both the
 * `<picture>` renderer and the preload-link emitter so the two paths
 * agree on every width / cache-path / fit-token decision.
 */
final class RenderContext
{
    /** Match the value used by the original `PictureRenderer::RATIO_EQUAL_EPSILON`. */
    private const RATIO_EQUAL_EPSILON = 0.001;

    public function __construct(
        public readonly ?float $effectiveRatio,
        public readonly Fit $effectiveFit,
        public readonly ?string $fitToken,
        public readonly ?int $effectiveMaxWidth,
        /** @var int[] */
        public readonly array $widths,
    ) {
    }

    /**
     * @param int[]|null $widthsOverride
     */
    public static function build(
        ResolvedImage $image,
        ?int $width,
        ?int $height,
        ?float $ratio,
        ?Fit $fit,
        ?array $widthsOverride,
        SrcsetBuilder $srcsetBuilder,
    ): self {
        [$effectiveRatio, $effectiveFit, $fitToken, $effectiveMaxWidth] =
            self::deriveFitState($image, $width, $height, $ratio, $fit);

        $widths = $srcsetBuilder->build($image->intrinsicWidth, $widthsOverride, $effectiveMaxWidth);

        return new self($effectiveRatio, $effectiveFit, $fitToken, $effectiveMaxWidth, $widths);
    }

    /**
     * Resolve `[width, height, fitToken]` for a single-URL emission.
     *
     * Mirrors `build()`'s effective-fit / `needsCrop` / `effectiveMaxWidth` logic
     * (via the shared `deriveFitState` helper) so the picture path and the
     * single-URL path agree on every cropping / cache-path decision — drift
     * here would mean `Image::url($w)` returns a URL that doesn't actually exist
     * in the rendered `<picture srcset>`.
     *
     * Default width selection: explicit `$width` if given, else median of the
     * `effectiveMaxWidth`-capped width pool — matching `PictureRenderer`'s
     * `<img src>` fallback choice (lib/View/PictureRenderer.php:89-90).
     *
     * @return array{0: int, 1: ?int, 2: ?string} [targetWidth, targetHeight, fitToken]
     */
    public static function resolveSingleVariant(
        ResolvedImage $image,
        ?int $width,
        ?int $height,
        ?float $ratio,
        ?Fit $fit,
        SrcsetBuilder $srcsetBuilder,
    ): array {
        [$effectiveRatio, , $fitToken, $effectiveMaxWidth] =
            self::deriveFitState($image, $width, $height, $ratio, $fit);

        if ($width !== null && $width > 0) {
            $targetWidth = $width;
        } else {
            $widths = $srcsetBuilder->build($image->intrinsicWidth, null, $effectiveMaxWidth);
            if ($widths === []) {
                $targetWidth = $image->intrinsicWidth > 0 ? $image->intrinsicWidth : 1;
            } else {
                $midIdx = (int) floor((count($widths) - 1) / 2);
                $targetWidth = $widths[$midIdx];
            }
        }

        $targetHeight = null;
        if ($height !== null && $height > 0) {
            $targetHeight = $height;
        } elseif ($effectiveRatio !== null && $effectiveRatio > 0 && $fitToken !== null) {
            $targetHeight = (int) round($targetWidth / $effectiveRatio);
        }

        return [$targetWidth, $targetHeight, $fitToken];
    }

    /**
     * Shared fit-state derivation. Returns `[effectiveRatio, effectiveFit,
     * fitToken, effectiveMaxWidth]`. Both `build()` (responsive `<picture>`)
     * and `resolveSingleVariant()` (single-URL emission) call this so the
     * `RATIO_EQUAL_EPSILON` short-circuit and the COVER/CONTAIN width cap
     * stay locked between the two paths.
     *
     * @return array{0: ?float, 1: Fit, 2: ?string, 3: ?int}
     */
    private static function deriveFitState(
        ResolvedImage $image,
        ?int $width,
        ?int $height,
        ?float $ratio,
        ?Fit $fit,
    ): array {
        $effectiveRatio = $ratio;
        if ($effectiveRatio === null && $height !== null && $height > 0 && $width !== null && $width > 0) {
            $effectiveRatio = $width / $height;
        }

        $effectiveFit = $fit ?? ($effectiveRatio !== null ? Fit::COVER : Fit::NONE);

        $intrinsicRatio = $image->aspectRatio();
        $needsCrop = $effectiveFit !== Fit::NONE
            && $effectiveRatio !== null
            && $intrinsicRatio > 0
            && abs($effectiveRatio - $intrinsicRatio) > self::RATIO_EQUAL_EPSILON;

        $fitToken = null;
        $effectiveMaxWidth = null;
        if ($needsCrop) {
            $fitToken = FitTokenBuilder::build($effectiveFit, $image->focalPoint);
            if ($effectiveFit !== Fit::STRETCH && $effectiveRatio > 0) {
                $effectiveMaxWidth = (int) min(
                    $image->intrinsicWidth,
                    (int) floor($image->intrinsicHeight * $effectiveRatio),
                );
            }
        }

        return [$effectiveRatio, $effectiveFit, $fitToken, $effectiveMaxWidth];
    }

    /**
     * Build the comma-separated `srcset` string for a given format.
     *
     * AVIF widths are filtered to those whose computed height is also ≥ 16:
     * libavif (the AV1 codec used by both Imagick's libheif binding and GD's
     * `imageavif()`) rejects sub-16×16 inputs with empty output, no exception.
     * Default `image_sizes` `16,32,…` combined with `ratio="16:9"` produces
     * h=9 at w=16 — empty cache file, broken `<picture>` source. WebP / JPG
     * have no such floor and keep their full pool. Browser `<picture>` source
     * picking falls through to WebP for slots where AVIF is dropped.
     *
     * @param array<string,scalar> $filterParams
     */
    public function buildSrcset(
        UrlBuilder $urlBuilder,
        ResolvedImage $image,
        string $format,
        ?int $quality,
        array $filterParams,
    ): string {
        $isAvif = strtolower($format) === 'avif';
        $intrinsicRatio = $image->aspectRatio();

        $entries = [];
        foreach ($this->widths as $w) {
            $h = ($this->effectiveRatio !== null && $this->fitToken !== null)
                ? (int) round($w / $this->effectiveRatio)
                : null;

            if ($isAvif && !self::satisfiesAvifMinDimension($w, $h, $intrinsicRatio)) {
                continue;
            }

            $url = $urlBuilder->build($image, $w, $format, $quality, $h, $this->fitToken, $filterParams);
            $entries[] = $url . ' ' . $w . 'w';
        }
        return implode(', ', $entries);
    }

    /**
     * Whether a (w, h) pair satisfies libavif's 16×16 minimum-dimension
     * floor. When `$h` is null (no explicit crop), the served image will
     * scale to width with the source's intrinsic aspect ratio; derive
     * `h = w / intrinsicRatio` to match what the encoder will actually
     * see at request time.
     */
    private static function satisfiesAvifMinDimension(int $w, ?int $h, float $intrinsicRatio): bool
    {
        if ($w < 16) {
            return false;
        }
        if ($h !== null) {
            return $h >= 16;
        }
        if ($intrinsicRatio > 0) {
            return (int) round($w / $intrinsicRatio) >= 16;
        }
        // No crop, no intrinsic ratio (zero-dim source) — accept; the encoder
        // will fail loudly, which is the right signal.
        return true;
    }
}
