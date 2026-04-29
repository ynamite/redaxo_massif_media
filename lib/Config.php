<?php

declare(strict_types=1);

namespace Ynamite\Media;

use rex_config;

final class Config
{
    public const ADDON = 'massif_media';

    public const KEY_SIGN_KEY = 'sign_key';
    public const KEY_FORMATS = 'formats';
    public const KEY_QUALITY = 'quality';
    public const KEY_DEVICE_SIZES = 'device_sizes';
    public const KEY_IMAGE_SIZES = 'image_sizes';
    public const KEY_DEFAULT_SIZES = 'default_sizes';
    public const KEY_LQIP_ENABLED = 'lqip_enabled';
    public const KEY_LQIP_WIDTH = 'lqip_width';
    public const KEY_LQIP_BLUR = 'lqip_blur';
    public const KEY_LQIP_QUALITY = 'lqip_quality';
    public const KEY_BLURHASH_ENABLED = 'blurhash_enabled';
    public const KEY_CDN_ENABLED = 'cdn_enabled';
    public const KEY_CDN_BASE = 'cdn_base';
    public const KEY_CDN_URL_TEMPLATE = 'cdn_url_template';
    public const KEY_METADATA_TTL_SECONDS = 'metadata_ttl_seconds';
    public const KEY_SENTINEL_TTL_SECONDS = 'sentinel_ttl_seconds';

    public const DEFAULTS = [
        self::KEY_FORMATS => ['avif', 'webp', 'jpg'],
        self::KEY_QUALITY => ['avif' => 50, 'webp' => 75, 'jpg' => 80],
        self::KEY_DEVICE_SIZES => [640, 750, 828, 1080, 1200, 1920, 2048, 3840],
        self::KEY_IMAGE_SIZES => [16, 32, 48, 64, 96, 128, 256, 384],
        self::KEY_DEFAULT_SIZES => '(min-width: 1280px) 640px, (min-width: 768px) 50vw, 90vw',
        self::KEY_LQIP_ENABLED => true,
        self::KEY_LQIP_WIDTH => 32,
        self::KEY_LQIP_BLUR => 40,
        self::KEY_LQIP_QUALITY => 40,
        self::KEY_BLURHASH_ENABLED => true,
        self::KEY_CDN_ENABLED => false,
        self::KEY_CDN_BASE => '',
        self::KEY_CDN_URL_TEMPLATE => '',
        self::KEY_METADATA_TTL_SECONDS => 7_776_000,
        self::KEY_SENTINEL_TTL_SECONDS => 60,
    ];

    public static function get(string $key, mixed $fallback = null): mixed
    {
        $value = rex_config::get(self::ADDON, $key, null);
        if ($value !== null) {
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
        return (array) self::get(self::KEY_FORMATS);
    }

    public static function quality(string $format): int
    {
        $map = (array) self::get(self::KEY_QUALITY);
        return (int) ($map[$format] ?? 80);
    }

    /** @return list<int> */
    public static function deviceSizes(): array
    {
        return array_map('intval', (array) self::get(self::KEY_DEVICE_SIZES));
    }

    /** @return list<int> */
    public static function imageSizes(): array
    {
        return array_map('intval', (array) self::get(self::KEY_IMAGE_SIZES));
    }

    public static function defaultSizes(): string
    {
        return (string) self::get(self::KEY_DEFAULT_SIZES);
    }

    public static function lqipEnabled(): bool
    {
        return (bool) self::get(self::KEY_LQIP_ENABLED);
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

    public static function blurhashEnabled(): bool
    {
        return (bool) self::get(self::KEY_BLURHASH_ENABLED);
    }

    public static function cdnEnabled(): bool
    {
        return (bool) self::get(self::KEY_CDN_ENABLED);
    }

    public static function cdnBase(): string
    {
        return rtrim((string) self::get(self::KEY_CDN_BASE), '/');
    }

    public static function cdnUrlTemplate(): string
    {
        return (string) self::get(self::KEY_CDN_URL_TEMPLATE);
    }
}
