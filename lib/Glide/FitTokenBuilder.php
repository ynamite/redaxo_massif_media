<?php

declare(strict_types=1);

namespace Ynamite\Media\Glide;

use Ynamite\Media\Enum\Fit;

/**
 * Translates a `Fit` + focal-point pair into the URL-side fit token used in
 * cache paths and Glide URLs (`cover-{X}-{Y}` / `contain` / `stretch`).
 *
 * Single source of truth for the `cover-X-Y` shape — `Endpoint::handle` and
 * `Server::cachePath` translate it to/from Glide's native `crop-X-Y` at the
 * Glide boundary.
 */
final class FitTokenBuilder
{
    public static function build(Fit $fit, ?string $focalPoint): string
    {
        if ($fit !== Fit::COVER) {
            return $fit->value;  // 'contain' or 'stretch'
        }

        // Cover requires focal coordinates as integers (Glide's `crop-X-Y` regex
        // rejects decimals on the first two coords — vendor/league/glide/src/Manipulators/Size.php:118).
        [$fx, $fy] = self::parseFocalToInts($focalPoint);
        return sprintf('cover-%d-%d', $fx, $fy);
    }

    /**
     * Parse "X% Y%" → [int, int] clamped to 0-100. Falls back to [50, 50] on
     * unparseable / missing input. MetadataReader::normalizeFocal already
     * produces this format from any focuspoint addon variant.
     *
     * @return array{0: int, 1: int}
     */
    public static function parseFocalToInts(?string $value): array
    {
        if ($value === null || !preg_match('/^([\d.]+)%\s+([\d.]+)%$/', $value, $m)) {
            return [50, 50];
        }
        $x = max(0, min(100, (int) round((float) $m[1])));
        $y = max(0, min(100, (int) round((float) $m[2])));
        return [$x, $y];
    }
}
