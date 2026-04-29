<?php

declare(strict_types=1);

namespace Ynamite\Media\Parser;

use Ynamite\Media\Image;

/**
 * Parses `REX_PIC[...]` placeholders in output content and substitutes the
 * full <picture> markup. Same renderer as the PHP API; just a different
 * front door for content editors / WYSIWYG fields.
 *
 * Syntax:
 *   REX_PIC[src="hero.jpg" alt="A view" width="1440" sizes="100vw"]
 *
 * Recognized attributes: src, alt, width, height, ratio, sizes, loading,
 * decoding, fetchpriority, focal, preload, class.
 */
final class REXPicParser
{
    public static function process(string $content): string
    {
        if (!str_contains($content, 'REX_PIC[')) {
            return $content;
        }

        $result = preg_replace_callback(
            '/REX_PIC\[([^\]]+)\]/',
            static function (array $m): string {
                $attrs = self::parseAttrs($m[1]);
                if (!isset($attrs['src']) || $attrs['src'] === '') {
                    return $m[0];
                }
                return Image::picture(
                    src: (string) $attrs['src'],
                    alt: $attrs['alt'] ?? null,
                    width: isset($attrs['width']) ? (int) $attrs['width'] : null,
                    height: isset($attrs['height']) ? (int) $attrs['height'] : null,
                    ratio: isset($attrs['ratio']) ? self::parseRatio((string) $attrs['ratio']) : null,
                    sizes: $attrs['sizes'] ?? null,
                    loading: $attrs['loading'] ?? 'lazy',
                    decoding: $attrs['decoding'] ?? 'async',
                    fetchPriority: $attrs['fetchpriority'] ?? 'auto',
                    focal: $attrs['focal'] ?? null,
                    preload: filter_var($attrs['preload'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    class: $attrs['class'] ?? null,
                );
            },
            $content,
        );

        return $result ?? $content;
    }

    /**
     * @return array<string, string>
     */
    private static function parseAttrs(string $body): array
    {
        $attrs = [];
        preg_match_all(
            '/(\w+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/',
            $body,
            $matches,
            PREG_SET_ORDER,
        );
        foreach ($matches as $m) {
            $key = strtolower($m[1]);
            $value = $m[2] !== '' ? $m[2] : ($m[3] ?? '');
            $attrs[$key] = $value;
        }
        return $attrs;
    }

    /**
     * Accept "16:9", "16/9", or a plain decimal "1.7777".
     */
    private static function parseRatio(string $value): ?float
    {
        if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*[:\/]\s*(\d+(?:\.\d+)?)\s*$/', $value, $m)) {
            $h = (float) $m[2];
            return $h > 0 ? ((float) $m[1]) / $h : null;
        }
        $f = (float) $value;
        return $f > 0 ? $f : null;
    }
}
