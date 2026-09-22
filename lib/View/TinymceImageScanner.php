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
 * `media_upload_settings.upload_media_manager_type`. Only `src` and `alt`
 * are carried over, the surrounding markup (`<p>`, …) stays untouched. Fails open like
 * {@see EditorContentScanner}: a throwing or empty render keeps the `<img>`.
 *
 * `sizes` resolves override → `Config::tinymceSizes()` → addon default. The
 * override lets a module set it in code before the page renders:
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

    /** `null` = let `Image::picture()` apply the addon default. */
    public static function sizes(): ?string
    {
        $sizes = self::$sizesOverride ?? Config::tinymceSizes();
        return $sizes === '' ? null : $sizes;
    }

    /**
     * @param null|callable(string $file, ?string $alt, ?string $sizes): string $render test seam, defaults to Image::picture()
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

        $render ??= static fn(string $file, ?string $alt, ?string $sizes): string => Image::picture($file, alt: $alt, sizes: $sizes);
        $typeAlt = implode('|', array_map(static fn(string $t): string => preg_quote($t, '/'), $types));
        $pattern = '/<img\b[^>]*\bsrc=["\']\/media\/(?:' . $typeAlt . ')\/([^"\'?#]+)[^"\']*["\'][^>]*>/i';

        return preg_replace_callback($pattern, static function (array $m) use ($render): string {
            $file = basename(rawurldecode($m[1]));
            $alt = preg_match('/\balt=["\']([^"\']*)["\']/i', $m[0], $a)
                ? html_entity_decode($a[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')
                : null;
            try {
                $rendered = $render($file, $alt === '' ? null : $alt, self::sizes());
            } catch (Throwable $e) {
                rex_logger::logException($e);
                return $m[0];
            }
            return $rendered === '' ? $m[0] : $rendered;
        }, $html) ?? $html;
    }

    /** @return list<string> */
    private static function mediaTypes(): array
    {
        $settings = rex_config::get('tinymce', 'media_upload_settings', []);
        $upload = is_array($settings) ? trim((string) ($settings['upload_media_manager_type'] ?? '')) : '';
        return array_values(array_unique(array_filter([self::MEDIAPOOL_TYPE, $upload])));
    }
}
