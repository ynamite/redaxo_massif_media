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
     * width. When cropping (cover / contain) demands a smaller upper bound — e.g.
     * a 1:1 crop on a 5000×4000 source caps usable width at 4000 — the caller
     * passes `$effectiveMaxWidth` and the pool is filtered against that too. The
     * effective cap is always included as the top variant.
     *
     * `width` (the caller's render-size hint) is NOT a srcset cap — that's
     * intentional, see commit c0aaa5d. Use `$override` to pass an explicit pool
     * when you specifically want a tighter set.
     *
     * @param int      $intrinsicWidth     Source image's natural width.
     * @param int[]|null $override         Optional explicit widths. Replaces the default pool.
     * @param int|null $effectiveMaxWidth  Optional secondary cap (cover/contain crop limit). null = use intrinsic.
     * @return int[]                       Sorted, deduped, all <= effective cap.
     */
    public function build(int $intrinsicWidth, ?array $override = null, ?int $effectiveMaxWidth = null): array
    {
        $candidates = $override !== null
            ? array_map('intval', $override)
            : array_unique(array_merge(Config::deviceSizes(), Config::imageSizes()));

        $cap = $intrinsicWidth;
        if ($effectiveMaxWidth !== null && $effectiveMaxWidth > 0 && $effectiveMaxWidth < $cap) {
            $cap = $effectiveMaxWidth;
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
