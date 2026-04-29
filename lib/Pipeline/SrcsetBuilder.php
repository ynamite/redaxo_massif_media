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
     * (next/image dual-pool model). Caps each candidate at min($intrinsic, $maxWidth).
     * Always includes the cap as the top variant.
     *
     * @param int      $intrinsicWidth Source image's natural width.
     * @param int|null $maxWidth       Optional intent cap (the `width` prop). null = use intrinsic.
     * @param int[]|null $override     Optional explicit widths. Replaces the default pool.
     * @return int[]                   Sorted, deduped, all <= cap.
     */
    public function build(int $intrinsicWidth, ?int $maxWidth = null, ?array $override = null): array
    {
        $candidates = $override !== null
            ? array_map('intval', $override)
            : array_unique(array_merge(Config::deviceSizes(), Config::imageSizes()));

        $cap = $intrinsicWidth;
        if ($maxWidth !== null && $maxWidth > 0 && $maxWidth < $cap) {
            $cap = $maxWidth;
        }

        $candidates = array_filter($candidates, static fn (int $w): bool => $w > 0 && $w <= $cap);

        if ($cap > 0 && !in_array($cap, $candidates, true)) {
            $candidates[] = $cap;
        }

        $candidates = array_values(array_unique($candidates));
        sort($candidates);

        return $candidates;
    }
}
