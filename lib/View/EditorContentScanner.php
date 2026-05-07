<?php

declare(strict_types=1);

namespace Ynamite\Media\View;

use rex_logger;
use Throwable;
use Ynamite\Media\Image;
use Ynamite\Media\Video;

/**
 * OUTPUT_FILTER pass that replaces literal `REX_PIC[…]` / `REX_VIDEO[…]`
 * substrings in rendered HTML with `<picture>` / `<video>` markup.
 *
 * REDAXO core's `rex_var::parse()` only runs on module/article templates
 * (cache-build path), never on stored editor input. Without a post-render
 * scan, a `REX_PIC[…]` typed into a rich-text field stays literal in the
 * page output. This scanner closes that gap so the README's WYSIWYG promise
 * is actually delivered.
 *
 * Cheap-skips when neither marker substring is present (one strpos pair
 * per page). Fails open: malformed tags log a warning and keep the literal
 * in place rather than blowing up the page.
 *
 * Limitations vs. the cache-build path:
 * - No nested REX_VAR support inside attribute values. By output-filter time
 *   all template-level rex_vars have already resolved, so nested syntax in
 *   editor input wouldn't have been picked up by the cache-build pass either.
 * - No `[`/`]` inside attribute values. Same constraint as REDAXO's tokenizer
 *   (`rex_var::getMatches`). Editor input that needs to display literal
 *   `REX_PIC[…]` text (code samples, docs) should escape with `&#91;`/`&#93;`.
 */
final class EditorContentScanner
{
    public static function scan(string $html): string
    {
        if (stripos($html, 'REX_PIC[') === false && stripos($html, 'REX_VIDEO[') === false) {
            return $html;
        }

        $html = preg_replace_callback(
            '/REX_PIC\[([^\]]+?)\]/s',
            static fn(array $m): string => self::renderPic($m),
            $html,
        ) ?? $html;

        $html = preg_replace_callback(
            '/REX_VIDEO\[([^\]]+?)\]/s',
            static fn(array $m): string => self::renderVideo($m),
            $html,
        ) ?? $html;

        return $html;
    }

    /**
     * @param array{0: string, 1: string} $match
     */
    private static function renderPic(array $match): string
    {
        $attrs = self::parseAttrs($match[1]);
        if (!isset($attrs['src']) || $attrs['src'] === '') {
            rex_logger::factory()->log(
                'warning',
                'massif_media: REX_PIC without src in editor content: ' . $match[0],
            );
            return $match[0];
        }

        try {
            // as="url" → single signed URL (poster / OG / CSS bg use case).
            if (($attrs['as'] ?? null) === 'url') {
                $rendered = Image::url(
                    src: $attrs['src'],
                    width: self::intOrNull($attrs['width'] ?? null),
                    height: self::intOrNull($attrs['height'] ?? null),
                    ratio: self::parseRatio($attrs['ratio'] ?? null),
                    format: self::stringOrNull($attrs['format'] ?? null),
                    quality: self::intOrNull($attrs['quality'] ?? null),
                    fit: self::stringOrNull($attrs['fit'] ?? null),
                    focal: self::stringOrNull($attrs['focal'] ?? null),
                    filters: self::collectFilters($attrs),
                );
            } else {
                $rendered = Image::picture(
                    src: $attrs['src'],
                    alt: self::stringOrNull($attrs['alt'] ?? null),
                    width: self::intOrNull($attrs['width'] ?? null),
                    height: self::intOrNull($attrs['height'] ?? null),
                    ratio: self::parseRatio($attrs['ratio'] ?? null),
                    sizes: self::stringOrNull($attrs['sizes'] ?? null),
                    loading: $attrs['loading'] ?? \Ynamite\Media\Enum\Loading::LAZY,
                    decoding: $attrs['decoding'] ?? \Ynamite\Media\Enum\Decoding::ASYNC,
                    fetchPriority: $attrs['fetchpriority'] ?? \Ynamite\Media\Enum\FetchPriority::AUTO,
                    focal: self::stringOrNull($attrs['focal'] ?? null),
                    preload: self::boolFromAttr($attrs['preload'] ?? null),
                    class: self::stringOrNull($attrs['class'] ?? null),
                    fit: self::stringOrNull($attrs['fit'] ?? null),
                    filters: self::collectFilters($attrs),
                    art: self::parseArt($attrs['art'] ?? null, $attrs['src']),
                );
            }
        } catch (Throwable $e) {
            rex_logger::logException($e);
            return $match[0];
        }

        // Empty render = ImageResolver couldn't load the source, or PictureRenderer
        // returned no usable widths. In editor-content context, leave the literal
        // visible so the editor can spot the typo, rather than silently swallowing
        // the tag (which is what the cache-build path does because there the
        // module author can debug differently).
        return $rendered === '' ? $match[0] : $rendered;
    }

    /**
     * @param array{0: string, 1: string} $match
     */
    private static function renderVideo(array $match): string
    {
        $attrs = self::parseAttrs($match[1]);
        if (!isset($attrs['src']) || $attrs['src'] === '') {
            rex_logger::factory()->log(
                'warning',
                'massif_media: REX_VIDEO without src in editor content: ' . $match[0],
            );
            return $match[0];
        }

        try {
            $rendered = Video::render(
                src: $attrs['src'],
                poster: self::stringOrNull($attrs['poster'] ?? null),
                width: self::intOrNull($attrs['width'] ?? null),
                height: self::intOrNull($attrs['height'] ?? null),
                alt: self::stringOrNull($attrs['alt'] ?? null),
                autoplay: self::boolFromAttr($attrs['autoplay'] ?? null),
                muted: self::boolFromAttr($attrs['muted'] ?? null),
                loop: self::boolFromAttr($attrs['loop'] ?? null),
                controls: self::boolFromAttr($attrs['controls'] ?? null, default: true),
                playsinline: self::boolFromAttr($attrs['playsinline'] ?? null, default: true),
                preload: $attrs['preload'] ?? 'metadata',
                loading: $attrs['loading'] ?? \Ynamite\Media\Enum\Loading::LAZY,
                class: self::stringOrNull($attrs['class'] ?? null),
                linkPreload: self::boolFromAttr($attrs['linkpreload'] ?? null),
            );
        } catch (Throwable $e) {
            rex_logger::logException($e);
            return $match[0];
        }

        return $rendered === '' ? $match[0] : $rendered;
    }

    /**
     * Parse REX_VAR attribute body into a key→value map.
     *
     * Accepts double-quoted, single-quoted, and bare values. Keys are
     * lowercased to match REX_VAR convention (`fetchpriority`, `linkpreload`).
     * Values are HTML-entity-decoded so editor-encoded entities (`&uuml;`,
     * `&quot;`, `&amp;`) round-trip correctly into builder args.
     *
     * @return array<string, string>
     */
    public static function parseAttrs(string $args): array
    {
        $result = [];
        if (!preg_match_all(
            '/(\w+)\s*=\s*(?:"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\'\\\\]|\\\\.)*)\'|(\S+))/s',
            $args,
            $matches,
            PREG_SET_ORDER,
        )) {
            return $result;
        }

        foreach ($matches as $m) {
            $key = strtolower($m[1]);
            $value = $m[2] !== '' ? $m[2] : (isset($m[3]) && $m[3] !== '' ? $m[3] : ($m[4] ?? ''));
            $result[$key] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return $result;
    }

    private static function intOrNull(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return ctype_digit($value) ? (int) $value : null;
    }

    private static function stringOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    private static function boolFromAttr(?string $value, bool $default = false): bool
    {
        if ($value === null) {
            return $default;
        }
        return (bool) filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /**
     * Mirror of `RexPic::parseRatio()` — keeps the cache-build path and the
     * post-render scan path honoring the same `16:9` / `4/3` / `1.5` shapes.
     */
    private static function parseRatio(?string $value): ?float
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*[:\/]\s*(\d+(?:\.\d+)?)\s*$/', $value, $m)) {
            $h = (float) $m[2];
            return $h > 0 ? ((float) $m[1]) / $h : null;
        }
        $f = (float) $value;
        return $f > 0 ? $f : null;
    }

    /**
     * Subset of attribute keys that map onto Glide filter params. Matches the
     * canonical list in `RexPic::buildFiltersArg()`; keep the two in lockstep.
     *
     * @param array<string, string> $attrs
     * @return array<string, mixed>
     */
    private static function collectFilters(array $attrs): array
    {
        $filterKeys = [
            'brightness', 'contrast', 'gamma', 'sharpen', 'blur', 'pixelate',
            'filter', 'bg', 'border', 'flip', 'orient',
            'mark', 'marks', 'markw', 'markh', 'markpos', 'markpad', 'markalpha', 'markfit',
        ];
        $out = [];
        foreach ($filterKeys as $key) {
            if (isset($attrs[$key]) && $attrs[$key] !== '') {
                $out[$key] = $attrs[$key];
            }
        }
        return $out;
    }

    /**
     * Decode the optional `art` attribute (JSON) into an `Image::picture`-
     * compatible variant list. Mirrors `RexPic::buildArtArg()` shape handling
     * (object-keyed-by-id, single bare variant, comma-separated rescue, list).
     * On parse / shape failure, returns `[]` and logs a warning.
     *
     * @return list<array<string, mixed>>
     */
    private static function parseArt(?string $raw, string $parentSrc): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, depth: 4, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $primaryError) {
            try {
                $decoded = json_decode('[' . $raw . ']', true, depth: 4, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                rex_logger::factory()->log(
                    'warning',
                    'massif_media: REX_PIC art attr JSON parse failed: ' . $primaryError->getMessage(),
                );
                return [];
            }
        }

        if (!is_array($decoded) || $decoded === []) {
            return [];
        }

        $first = $decoded[array_key_first($decoded)];
        if (!is_array($first)) {
            $decoded = [$decoded];
        } else {
            $decoded = array_values($decoded);
        }

        $allowed = ['media', 'src', 'width', 'height', 'ratio', 'focal', 'fit', 'filters'];
        $clean = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $row = [];
            foreach ($allowed as $key) {
                if (array_key_exists($key, $entry)) {
                    $row[$key] = $entry[$key];
                }
            }
            if ((!array_key_exists('src', $row) || !is_string($row['src']) || trim($row['src']) === '')
                && trim($parentSrc) !== ''
            ) {
                $row['src'] = $parentSrc;
            }
            if (!isset($row['media'], $row['src'])
                || !is_string($row['media'])
                || trim($row['media']) === ''
                || !is_string($row['src'])
                || trim($row['src']) === ''
            ) {
                continue;
            }
            $clean[] = $row;
        }
        return $clean;
    }
}
