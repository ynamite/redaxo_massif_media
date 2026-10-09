<?php

declare(strict_types=1);

namespace Ynamite\Media\View;

use rex_config;
use rex_logger;
use Throwable;
use Ynamite\Media\Config;
use Ynamite\Media\Image;

/**
 * OUTPUT_FILTER pass that swaps the plain `<img src="/media/<type>/<file>">`
 * the tinymce addon inserts for uploaded images with `Image::picture()` markup.
 *
 * `<type>` is `tiny` (hardcoded in tinymce's mediapool-insert JS for raster
 * images) or, if configured, the upload type from
 * `media_upload_settings.upload_media_manager_type`. Carried over: `src`,
 * `alt`, `class`, and the display width tiny set — the `width` attribute, else
 * `width: Npx` in `style` (px only). Height is ignored on purpose: it derives
 * from the intrinsic ratio, which is what tiny shows with locked proportions,
 * and passing both would push near-equal ratios (tiny rounds the height) into
 * the cover-crop path. The rest of `style` is dropped. The surrounding markup
 * (`<p>`, …) stays untouched. Fails open like {@see EditorContentScanner}: a
 * throwing or empty render keeps the `<img>`.
 *
 * `sizes` resolves override → width-derived (`auto, (max-width: Wpx) 100vw,
 * Wpx`) → `Config::tinymceSizes()` → addon default. The override lets a module
 * set it in code before the page renders:
 *
 *     TinymceImageScanner::setSizes('(min-width: 768px) 50vw, 100vw');
 */
final class TinymceImageScanner
{
    /** Media-manager type tinymce's mediapool insert (assets/scripts/base.js) hardcodes. */
    private const MEDIAPOOL_TYPE = 'tiny';

    private static ?string $sizesOverride = null;

    /** `null` reverts to the configured value. */
    public static function setSizes(?string $sizes): void
    {
        self::$sizesOverride = $sizes;
    }

    /**
     * `null` = let `Image::picture()` apply the addon default. A known display
     * width beats the site-wide setting; `auto,` is spelled out because the
     * renderer only prefixes it when no explicit `sizes` is given.
     */
    public static function sizes(?int $width = null): ?string
    {
        if (self::$sizesOverride === null && $width !== null) {
            return 'auto, (max-width: ' . $width . 'px) 100vw, ' . $width . 'px';
        }
        $sizes = self::$sizesOverride ?? Config::tinymceSizes();
        return $sizes === '' ? null : $sizes;
    }

    /**
     * @param null|callable(string $file, ?string $alt, ?int $width, ?string $sizes, ?string $class): string $render test seam, defaults to Image::picture()
     */
    public static function scan(string $html, ?callable $render = null): string
    {
        if (stripos($html, '<img') === false) {
            return $html;
        }
        $types = array_filter(self::mediaTypes(), static fn(string $t): bool => str_contains($html, '/media/' . $t . '/'));
        if ($types === []) {
            return $html;
        }

        $render ??= static fn(string $file, ?string $alt, ?int $width, ?string $sizes, ?string $class): string
            => Image::picture($file, alt: $alt, width: $width, sizes: $sizes, class: $class);
        $typeAlt = implode('|', array_map(static fn(string $t): string => preg_quote($t, '/'), $types));
        $pattern = '/<img\b[^>]*\bsrc=["\']\/media\/(?:' . $typeAlt . ')\/([^"\'?#]+)[^"\']*["\'][^>]*>/i';

        return preg_replace_callback($pattern, static function (array $m) use ($render): string {
            $file = basename(rawurldecode($m[1]));
            $alt = self::attr($m[0], 'alt');
            $class = trim((string) self::attr($m[0], 'class'));
            $width = self::width($m[0]);
            try {
                $rendered = $render($file, $alt === '' ? null : $alt, $width, self::sizes($width), $class === '' ? null : $class);
            } catch (Throwable $e) {
                rex_logger::logException($e);
                return $m[0];
            }
            return $rendered === '' ? $m[0] : $rendered;
        }, $html) ?? $html;
    }

    /** Decoded value of a quoted attribute; the lookbehind keeps `data-width` / `min-width` out. */
    private static function attr(string $tag, string $name): ?string
    {
        return preg_match('/(?<![\w-])' . $name . '=(["\'])(.*?)\1/is', $tag, $a)
            ? html_entity_decode($a[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : null;
    }

    /** Display width tiny set: `width` attribute, else `width: Npx` in `style`. */
    private static function width(string $tag): ?int
    {
        $width = null;
        if (preg_match('/(?<![\w-])width=(["\']?)(\d+)\1(?=[\s\/>])/i', $tag, $w)) {
            $width = (int) $w[2];
        } elseif (preg_match('/(?<![\w-])width\s*:\s*(\d+(?:\.\d+)?)px\b/i', (string) self::attr($tag, 'style'), $w)) {
            $width = (int) round((float) $w[1]);
        }
        return $width > 0 ? $width : null;
    }

    /** @return list<string> */
    private static function mediaTypes(): array
    {
        $settings = rex_config::get('tinymce', 'media_upload_settings', []);
        $upload = is_array($settings) ? trim((string) ($settings['upload_media_manager_type'] ?? '')) : '';
        return array_values(array_unique(array_filter([self::MEDIAPOOL_TYPE, $upload])));
    }
}
