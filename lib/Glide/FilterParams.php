<?php

declare(strict_types=1);

namespace Ynamite\Media\Glide;

/**
 * Central translation + validation surface for Glide filter passthrough.
 *
 * - FRIENDLY_TO_GLIDE accepts both friendly long names (brightness) AND
 *   Glide short names (bri) as keys; both map to the Glide short name.
 * - RANGES gives [min, max] per numeric param for clamping.
 * - normalize() takes a user-supplied keyed array and returns a clean array
 *   keyed by Glide short name with values clamped/validated/dropped.
 */
final class FilterParams
{
    public const FRIENDLY_TO_GLIDE = [
        'brightness' => 'bri',
        'contrast'   => 'con',
        'gamma'      => 'gam',
        'sharpen'    => 'sharp',
        'blur'       => 'blur',
        'pixelate'   => 'pixel',
        'bri'        => 'bri',
        'con'        => 'con',
        'gam'        => 'gam',
        'sharp'      => 'sharp',
        'pixel'      => 'pixel',
        'filter'     => 'filt',
        'filt'       => 'filt',
        'flip'       => 'flip',
        'orient'     => 'orient',
        'border'     => 'border',
        'bg'         => 'bg',
        'mark'       => 'mark',
        'marks'      => 'marks',
        'markw'      => 'markw',
        'markh'      => 'markh',
        'markpos'    => 'markpos',
        'markpad'    => 'markpad',
        'markalpha'  => 'markalpha',
        'markfit'    => 'markfit',
    ];

    public const RANGES = [
        'bri'       => [-100, 100],
        'con'       => [-100, 100],
        'gam'       => [0.1, 9.99],
        'sharp'     => [0, 100],
        'blur'      => [0, 100],
        'pixel'     => [0, 1000],
        'marks'     => [0.0, 1.0],
        'markalpha' => [0, 100],
    ];

    /** Glide params whose values are hex colors. */
    public const HEX_PARAMS = ['bg'];

    /**
     * Translate friendly-keyed array to Glide-keyed array, applying clamps and
     * dropping invalid entries. Output is ready for the cache-key hash.
     *
     * @param array<string, scalar> $params
     * @return array<string, scalar>
     */
    public static function normalize(array $params): array
    {
        $out = [];
        foreach ($params as $key => $value) {
            $glideKey = self::FRIENDLY_TO_GLIDE[$key] ?? null;
            if ($glideKey === null) {
                continue;
            }

            if (in_array($glideKey, self::HEX_PARAMS, true)) {
                $hex = self::validateHex((string) $value);
                if ($hex !== null) {
                    $out[$glideKey] = strtolower($hex);
                }
                continue;
            }

            if (isset(self::RANGES[$glideKey])) {
                $out[$glideKey] = self::clamp($glideKey, $value);
                continue;
            }

            $out[$glideKey] = $value;
        }
        return $out;
    }

    public static function validateHex(string $value): ?string
    {
        return preg_match('/^[0-9a-f]{6}$/i', $value) === 1 ? $value : null;
    }

    public static function clamp(string $glideParam, int|float $value): int|float
    {
        $range = self::RANGES[$glideParam] ?? null;
        if ($range === null) {
            return $value;
        }
        [$min, $max] = $range;
        if (is_int($min) && is_int($max)) {
            return max($min, min($max, (int) $value));
        }
        return max((float) $min, min((float) $max, (float) $value));
    }
}
