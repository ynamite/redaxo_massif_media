<?php

declare(strict_types=1);

namespace Ynamite\Media\Pipeline;

use Ynamite\Media\Config;

final class SrcsetBuilder
{
    /**
     * Compute the list of widths to generate for an image.
     *
     * Pool defaults to the union of Config::deviceSizes() and Config::imageSizes()
     * (next/image dual-pool model). Caps each candidate at the source's intrinsic
     * width — never generates variants larger than the source can produce. The
     * intrinsic itself is always included as the top variant.
     *
     * The `width` prop on Image::picture() / REX_PIC is intentionally NOT used
     * to cap the srcset. It's a layout hint (HTML `width=` attribute, CLS
     * reservation), not a hard ceiling — capping there would starve HiDPI / 2x /
     * 3x screens of crisp variants on a CSS-`width` of e.g. 720px. Matches
     * next/image's responsive behavior: when a `sizes` attribute is present
     * (which it always is in this pipeline — defaults to `Config::defaultSizes()`),
     * the browser picks the right variant from the full pool based on the actual
     * rendered size × DPR. Use `->widths([...])` to override the pool when you
     * specifically want a tighter set.
     *
     * @param int      $intrinsicWidth Source image's natural width.
     * @param int[]|null $override     Optional explicit widths. Replaces the default pool.
     * @return int[]                   Sorted, deduped, all <= intrinsicWidth.
     */
    public function build(int $intrinsicWidth, ?array $override = null): array
    {
        $candidates = $override !== null
            ? array_map('intval', $override)
            : array_unique(array_merge(Config::deviceSizes(), Config::imageSizes()));

        $candidates = array_filter($candidates, static fn (int $w): bool => $w > 0 && $w <= $intrinsicWidth);

        if ($intrinsicWidth > 0 && !in_array($intrinsicWidth, $candidates, true)) {
            $candidates[] = $intrinsicWidth;
        }

        $candidates = array_values(array_unique($candidates));
        sort($candidates);

        return $candidates;
    }
}
