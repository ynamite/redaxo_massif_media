<?php

declare(strict_types=1);

namespace Ynamite\Media\Var;

use rex_var;

/**
 * Native REDAXO REX_VAR for `<picture>` markup in slice content.
 *
 * Syntax (named attributes; supports embedded REX_VARs):
 *
 *   REX_PIC[src="hero.jpg" alt="A view" width="1440" sizes="100vw"]
 *
 * Recognized attributes: src (required), alt, width, height, ratio, sizes,
 * loading, decoding, fetchpriority, focal, preload, class.
 *
 * Substitution happens during article cache generation (REDAXO core's
 * `replaceObjectVars` calls `rex_var::parse` per slice). The PHP expression
 * returned from `getOutput()` is baked into the cached article and evaluated
 * fresh on every render — config (sizes, formats) changes take effect on the
 * next cache rebuild.
 */
final class RexPic extends rex_var
{
    protected function getOutput(): string|false
    {
        $src = $this->getParsedArg('src');
        if ($src === null) {
            return false;
        }

        $args = ['src: ' . $src];

        // Pass-through string args. getParsedArg returns either an already-
        // quoted PHP string literal, a bare numeric, or null. Missing args
        // fall back to Image::picture()'s own defaults (which include enum
        // defaults for loading/decoding/fetchPriority that we cannot emit
        // as a string here without re-importing the enums).
        foreach (['alt', 'width', 'height', 'sizes', 'loading', 'decoding', 'focal', 'class', 'fit'] as $key) {
            $val = $this->getParsedArg($key);
            if ($val !== null) {
                $args[] = $key . ': ' . $val;
            }
        }

        // REX_PIC attribute is lowercase; Image::picture parameter is camelCase.
        $fp = $this->getParsedArg('fetchpriority');
        if ($fp !== null) {
            $args[] = 'fetchPriority: ' . $fp;
        }

        // ratio: "16:9" / "16/9" / decimal → float literal
        $ratioRaw = $this->getArg('ratio');
        if (is_string($ratioRaw) && $ratioRaw !== '') {
            $f = self::parseRatio($ratioRaw);
            if ($f !== null) {
                $args[] = 'ratio: ' . $f;
            }
        }

        // preload: any truthy attribute → bool literal
        $preloadRaw = $this->getArg('preload');
        if ($preloadRaw !== null) {
            $args[] = 'preload: ' . (filter_var($preloadRaw, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false');
        }

        return '\\Ynamite\\Media\\Image::picture(' . implode(', ', $args) . ')';
    }

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
