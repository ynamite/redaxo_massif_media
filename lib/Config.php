<?php

declare(strict_types=1);

namespace Ynamite\Media;

use rex_config;

final class Config
{
    public const ADDON = 'massif_media';

    public const KEY_SIGN_KEY = 'sign_key';
    public const KEY_FORMATS = 'formats';
    public const KEY_QUALITY_AVIF = 'quality_avif';
    public const KEY_QUALITY_WEBP = 'quality_webp';
    public const KEY_QUALITY_JPG = 'quality_jpg';
    public const KEY_DEVICE_SIZES = 'device_sizes';
    public const KEY_IMAGE_SIZES = 'image_sizes';
    public const KEY_DEFAULT_SIZES = 'default_sizes';
    public const KEY_LQIP_ENABLED = 'lqip_enabled';
    public const KEY_LQIP_WIDTH = 'lqip_width';
    public const KEY_LQIP_BLUR = 'lqip_blur';
    public const KEY_LQIP_QUALITY = 'lqip_quality';
    public const KEY_CDN_ENABLED = 'cdn_enabled';
    public const KEY_CDN_BASE = 'cdn_base';
    public const KEY_CDN_URL_TEMPLATE = 'cdn_url_template';
    public const KEY_METADATA_TTL_SECONDS = 'metadata_ttl_seconds';
    public const KEY_SENTINEL_TTL_SECONDS = 'sentinel_ttl_seconds';

    /**
     * Defaults shipped with the addon. List-shaped values are stored as
     * comma-separated strings so rex_config_form text fields can edit them
     * directly; the typed accessors below split them on read.
     */
    public const DEFAULTS = [
        self::KEY_FORMATS => 'avif,webp,jpg',
        self::KEY_QUALITY_AVIF => 50,
        self::KEY_QUALITY_WEBP => 75,
        self::KEY_QUALITY_JPG => 80,
        self::KEY_DEVICE_SIZES => '640,750,828,1080,1200,1440,1600,1920,2048,3840',
        self::KEY_IMAGE_SIZES => '16,32,48,64,96,128,256,384',
        self::KEY_DEFAULT_SIZES => '(min-width: 1280px) 640px, (min-width: 768px) 50vw, 90vw',
        self::KEY_LQIP_ENABLED => 1,
        self::KEY_LQIP_WIDTH => 32,
        self::KEY_LQIP_BLUR => 5,
        self::KEY_LQIP_QUALITY => 40,
        self::KEY_CDN_ENABLED => 0,
        self::KEY_CDN_BASE => '',
        self::KEY_CDN_URL_TEMPLATE => '',
        self::KEY_METADATA_TTL_SECONDS => 7_776_000,
        self::KEY_SENTINEL_TTL_SECONDS => 60,
    ];

    public static function get(string $key, mixed $fallback = null): mixed
    {
        $value = rex_config::get(self::ADDON, $key, null);
        if ($value !== null && $value !== '') {
            return $value;
        }
        return $fallback ?? (self::DEFAULTS[$key] ?? null);
    }

    public static function set(string $key, mixed $value): bool
    {
        return rex_config::set(self::ADDON, $key, $value);
    }

    public static function signKey(): string
    {
        return (string) self::get(self::KEY_SIGN_KEY, '');
    }

    /** @return list<string> */
    public static function formats(): array
    {
        $list = self::splitList((string) self::get(self::KEY_FORMATS));
        return array_values(array_filter(array_map('strtolower', $list)));
    }

    public static function quality(string $format): int
    {
        $key = match (strtolower($format)) {
            'avif' => self::KEY_QUALITY_AVIF,
            'webp' => self::KEY_QUALITY_WEBP,
            'jpg', 'jpeg' => self::KEY_QUALITY_JPG,
            default => self::KEY_QUALITY_JPG,
        };
        return (int) self::get($key, 80);
    }

    /** @return list<int> */
    public static function deviceSizes(): array
    {
        return self::splitIntList((string) self::get(self::KEY_DEVICE_SIZES));
    }

    /** @return list<int> */
    public static function imageSizes(): array
    {
        return self::splitIntList((string) self::get(self::KEY_IMAGE_SIZES));
    }

    public static function defaultSizes(): string
    {
        return (string) self::get(self::KEY_DEFAULT_SIZES);
    }

    public static function lqipEnabled(): bool
    {
        return self::checkboxBool(self::KEY_LQIP_ENABLED);
    }

    public static function lqipWidth(): int
    {
        return (int) self::get(self::KEY_LQIP_WIDTH);
    }

    public static function lqipBlur(): int
    {
        return (int) self::get(self::KEY_LQIP_BLUR);
    }

    public static function lqipQuality(): int
    {
        return (int) self::get(self::KEY_LQIP_QUALITY);
    }

    public static function cdnEnabled(): bool
    {
        return self::checkboxBool(self::KEY_CDN_ENABLED);
    }

    public static function cdnBase(): string
    {
        return rtrim((string) self::get(self::KEY_CDN_BASE), '/');
    }

    public static function cdnUrlTemplate(): string
    {
        return (string) self::get(self::KEY_CDN_URL_TEMPLATE);
    }

    /**
     * Read a checkbox-backed config value as bool. Two storage shapes to handle:
     *   1. `rex_config_form::addCheckboxField` stores selected options pipe-delimited
     *      (`|1|` for a single ticked option with value `1`, `''` for unticked,
     *      `|1|2|` for multi-option checkboxes). A naive `(bool) (int)` cast on
     *      `'|1|'` evaluates to `false` because PHP's int cast on a string that
     *      doesn't start with a digit returns `0` — silently flipping any
     *      user-toggled checkbox to "off". Strip pipes first, then int-cast.
     *   2. `Config::DEFAULTS` uses plain ints (`1` / `0`) for fresh installs that
     *      have no saved value yet.
     *
     * Bypasses `Config::get` because that method's empty-string fallback would
     * coerce a user-unticked checkbox (saved as `''`) back to the default (`1`),
     * making it impossible to actually disable a checkbox-backed setting from
     * the UI. We only fall back to DEFAULTS when the key has truly never been
     * set (`rex_config::get` returns `null`).
     */
    private static function checkboxBool(string $key): bool
    {
        $raw = rex_config::get(self::ADDON, $key, null);
        if ($raw === null) {
            $raw = self::DEFAULTS[$key] ?? 0;
        }
        if (is_string($raw)) {
            $raw = trim($raw, '|');
        }
        return (bool) (int) $raw;
    }

    /**
     * Split a comma/semicolon/whitespace-separated list into a string list.
     *
     * @return list<string>
     */
    private static function splitList(string $csv): array
    {
        $items = preg_split('/[\s,;]+/', trim($csv)) ?: [];
        return array_values(array_filter($items, static fn(string $s): bool => $s !== ''));
    }

    /**
     * Split + cast to positive integers.
     *
     * @return list<int>
     */
    private static function splitIntList(string $csv): array
    {
        $ints = array_map('intval', self::splitList($csv));
        return array_values(array_filter($ints, static fn(int $i): bool => $i > 0));
    }
}
