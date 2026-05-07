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
    public const KEY_COLOR_ENABLED = 'color_enabled';
    public const KEY_CDN_ENABLED = 'cdn_enabled';
    public const KEY_CDN_BASE = 'cdn_base';
    public const KEY_CDN_URL_TEMPLATE = 'cdn_url_template';
    public const KEY_METADATA_TTL_SECONDS = 'metadata_ttl_seconds';
    public const KEY_SENTINEL_TTL_SECONDS = 'sentinel_ttl_seconds';
    public const KEY_EXTERNAL_TTL_SECONDS = 'external_ttl_seconds';
    public const KEY_EXTERNAL_TIMEOUT_SECONDS = 'external_timeout_seconds';
    public const KEY_EXTERNAL_MAX_BYTES = 'external_max_bytes';
    public const KEY_EXTERNAL_HOST_ALLOWLIST = 'external_host_allowlist';

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
        self::KEY_COLOR_ENABLED => 1,
        self::KEY_CDN_ENABLED => 0,
        self::KEY_CDN_BASE => '',
        self::KEY_CDN_URL_TEMPLATE => '',
        self::KEY_METADATA_TTL_SECONDS => 7_776_000,
        self::KEY_SENTINEL_TTL_SECONDS => 60,
        self::KEY_EXTERNAL_TTL_SECONDS => 86_400,        // 24h
        self::KEY_EXTERNAL_TIMEOUT_SECONDS => 15,
        self::KEY_EXTERNAL_MAX_BYTES => 26_214_400,      // 25 MB
        self::KEY_EXTERNAL_HOST_ALLOWLIST => '',         // empty = allow any host
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

    /**
     * User-configured raster formats, raw and unfiltered. Use {@see renderableFormats()}
     * for output paths — the configured list may include formats the server can't
     * actually encode (e.g. AVIF without libheif), and emitting `<source type="image/avif">`
     * for an URL the cache-miss handler can't fulfil produces broken images on AVIF-capable
     * browsers (`<picture>` does not auto-fallback when a chosen `<source>` URL fails).
     *
     * @return list<string>
     */
    public static function formats(): array
    {
        $list = self::splitList((string) self::get(self::KEY_FORMATS));
        return array_values(array_filter(array_map('strtolower', $list)));
    }

    /**
     * Configured formats filtered to those the server can actually encode. This is
     * the list to emit into `<picture>`, `<link rel="preload">`, and default-format
     * URL generation — any format here is guaranteed to fulfil at the cache-miss
     * endpoint. JPEG/PNG/GIF are always available (PHP's built-in raster support);
     * AVIF and WebP go through Imagick's encoder list.
     *
     * @return list<string>
     */
    public static function renderableFormats(): array
    {
        return array_values(array_filter(self::formats(), self::canServerEncode(...)));
    }

    /**
     * Whether the active Glide driver can encode the given format.
     *
     * Mirrors Glide's own driver selection (see {@see \Ynamite\Media\Glide\Server::create}):
     * Imagick when the extension is loaded, GD otherwise — `league/glide`
     * supports those two and only those two via `intervention/image` v3.
     * Gating on the *driver Glide will actually use* avoids false positives
     * where some other backend would support the format but Glide can't
     * reach it (e.g. Imagick lacks AVIF, GD has it: the OR-check would say
     * yes, Glide picks Imagick, encoding fails).
     *
     * Capability is read from each driver's metadata API:
     *   - Imagick → `Imagick::queryFormats()` (cached request-scope, single
     *     instance) — same approach `media_negotiator/lib/Helper.php` uses
     *     successfully across thousands of REDAXO installs.
     *   - GD     → `function_exists('image…')` plus `gd_info()['… Support']`.
     *
     * 1.0.4 added a 1×1 encode probe on top of `queryFormats()` to guard
     * against the theoretical case of a registered-but-broken codec library.
     * That guard never caught a real-world false positive (the AVIF failures
     * blamed on it traced to directory permissions and umask handling, not
     * `queryFormats()` over-reporting), and the 1×1 probe itself produces
     * false negatives on libheif builds that reject sub-minimum dimensions —
     * making Imagick-loaded servers with working AVIF unable to emit it.
     * Drop the probe; trust the metadata APIs.
     */
    public static function canServerEncode(string $format): bool
    {
        $fmt = strtolower($format);

        // PHP/GD baseline: every sane host ships at least GD with these
        // codecs. Skipping the driver-specific check here means an exotic
        // Imagick build that somehow lacks JPEG (which would also break
        // half of REDAXO) doesn't accidentally gate fallback emission.
        if (in_array($fmt, ['jpg', 'jpeg', 'png', 'gif'], true)) {
            return true;
        }

        if (self::$serverFormatCapability === null) {
            if (extension_loaded('imagick')) {
                $imagickFormats = self::imagickQueryFormatsCached();
                self::$serverFormatCapability = [
                    'avif' => in_array('AVIF', $imagickFormats, true),
                    'webp' => in_array('WEBP', $imagickFormats, true),
                ];
            } else {
                $gdInfo = function_exists('gd_info') ? gd_info() : [];
                self::$serverFormatCapability = [
                    'avif' => function_exists('imageavif') && !empty($gdInfo['AVIF Support']),
                    'webp' => function_exists('imagewebp') && !empty($gdInfo['WebP Support']),
                ];
            }
        }

        return !empty(self::$serverFormatCapability[$fmt]);
    }

    /**
     * Cache `Imagick::queryFormats()` request-scope. Single instance per
     * request — `queryFormats()` is a static-style query against the
     * registered modules, instantiating once is enough.
     *
     * @return list<string>
     */
    private static function imagickQueryFormatsCached(): array
    {
        if (self::$imagickFormatsCache !== null) {
            return self::$imagickFormatsCache;
        }
        $im = new \Imagick();
        try {
            self::$imagickFormatsCache = $im->queryFormats();
        } finally {
            $im->clear();
            $im->destroy();
        }
        return self::$imagickFormatsCache;
    }

    /** @var array<string,bool>|null */
    private static ?array $serverFormatCapability = null;

    /** @var list<string>|null */
    private static ?array $imagickFormatsCache = null;

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

    public static function colorEnabled(): bool
    {
        return self::checkboxBool(self::KEY_COLOR_ENABLED);
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

    public static function externalTtlSeconds(): int
    {
        return max(0, (int) self::get(self::KEY_EXTERNAL_TTL_SECONDS));
    }

    public static function externalTimeoutSeconds(): int
    {
        return max(1, (int) self::get(self::KEY_EXTERNAL_TIMEOUT_SECONDS));
    }

    public static function externalMaxBytes(): int
    {
        return max(1024, (int) self::get(self::KEY_EXTERNAL_MAX_BYTES));
    }

    /**
     * One regex per non-empty line. Empty list = allow any host.
     *
     * @return list<string>
     */
    public static function externalHostAllowlist(): array
    {
        $raw = (string) self::get(self::KEY_EXTERNAL_HOST_ALLOWLIST);
        if ($raw === '') {
            return [];
        }
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        return array_values(array_filter(
            array_map('trim', $lines),
            static fn(string $s): bool => $s !== '',
        ));
    }

    /**
     * Read a checkbox-backed config value as bool. Three storage shapes to handle:
     *   1. `rex_config_form::addCheckboxField` stores ticked checkboxes pipe-
     *      delimited (`'|1|'` for a single ticked option with value `1`,
     *      `'|1|2|'` for multiples). A naive `(bool) (int)` cast on `'|1|'`
     *      evaluates to `false` because PHP's int cast on a string that doesn't
     *      start with a digit returns `0` — silently flipping any user-toggled
     *      checkbox to "off". Strip pipes first, then int-cast.
     *   2. **Unticked saves persist as `null`**, NOT empty string. Browsers
     *      don't submit unchecked checkboxes, so REDAXO's form sees the field
     *      missing from `$_POST`, calls `setValue(null)`, and `config_form::save`
     *      hands `rex_config::set(..., null)` to storage. The naive read can't
     *      distinguish "user explicitly unticked" (null) from "key was never
     *      written" (also null) because `rex_config::get` collapses both via
     *      `??`. `rex_config::has` uses `isset()` which returns false for null
     *      values too, so we go through the whole namespace dict and use
     *      `array_key_exists` — that's the only way to tell these apart.
     *   3. `Config::DEFAULTS` ints (`1` / `0`) for fresh installs (key truly
     *      never written). Used only when `array_key_exists` says the key is
     *      not present.
     *
     * Result table:
     *   never written  → DEFAULTS ($key) → bool cast    (default-on respected)
     *   '|1|' / '|0|2|' / etc.  → trim('|') → int cast  (ticked = true)
     *   ''             → int cast → 0 → false           (legacy unticked shape)
     *   null           → false                          (current unticked shape)
     */
    private static function checkboxBool(string $key): bool
    {
        $namespace = rex_config::get(self::ADDON);
        if (!is_array($namespace) || !array_key_exists($key, $namespace)) {
            // Truly never written — fall back to the shipped default.
            $raw = self::DEFAULTS[$key] ?? 0;
        } else {
            $raw = $namespace[$key];
        }
        if ($raw === null) {
            // Explicit null from rex_config_form's unticked-checkbox save path.
            return false;
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
