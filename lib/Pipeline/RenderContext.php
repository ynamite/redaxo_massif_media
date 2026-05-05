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

        $widths = $srcsetBuilder->build($image->intrinsicWidth, $widthsOverride, $effectiveMaxWidth);

        return new self($effectiveRatio, $effectiveFit, $fitToken, $effectiveMaxWidth, $widths);
    }

    /**
     * Build the comma-separated `srcset` string for a given format.
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
        $entries = [];
        foreach ($this->widths as $w) {
            $h = ($this->effectiveRatio !== null && $this->fitToken !== null)
                ? (int) round($w / $this->effectiveRatio)
                : null;
            $url = $urlBuilder->build($image, $w, $format, $quality, $h, $this->fitToken, $filterParams);
            $entries[] = $url . ' ' . $w . 'w';
        }
        return implode(', ', $entries);
    }
}
