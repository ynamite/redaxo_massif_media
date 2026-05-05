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

        // `as="url"` flips the output mode: emit a single signed URL instead
        // of `<picture>` markup. Branch on the RAW arg (not getParsedArg) since
        // getParsedArg returns a quoted PHP literal — same gotcha as the
        // existing preload attribute below.
        if ($this->getArg('as') === 'url') {
            return self::buildUrlCall($src, $this->collectUrlArgs());
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

        $filterArg = self::buildFiltersArg($this);
        if ($filterArg !== null) {
            $args[] = $filterArg;
        }

        return '\\Ynamite\\Media\\Image::picture(' . implode(', ', $args) . ')';
    }

    /**
     * Collect the subset of attributes relevant to a single-URL emission:
     * width / height / fit / focal / format / quality / ratio + filters.
     * Skips alt, sizes, loading, decoding, fetchpriority, preload, class —
     * those only make sense on the rendered `<img>` element.
     *
     * @return list<string> Pre-formatted PHP named-arg fragments.
     */
    private function collectUrlArgs(): array
    {
        $args = [];

        foreach (['width', 'height', 'focal', 'fit', 'format'] as $key) {
            $val = $this->getParsedArg($key);
            if ($val !== null) {
                $args[] = $key . ': ' . $val;
            }
        }

        $quality = $this->getArg('quality');
        if (is_string($quality) && $quality !== '' && ctype_digit($quality)) {
            $args[] = 'quality: ' . (int) $quality;
        }

        $ratioRaw = $this->getArg('ratio');
        if (is_string($ratioRaw) && $ratioRaw !== '') {
            $f = self::parseRatio($ratioRaw);
            if ($f !== null) {
                $args[] = 'ratio: ' . $f;
            }
        }

        $filterArg = self::buildFiltersArg($this);
        if ($filterArg !== null) {
            $args[] = $filterArg;
        }

        return $args;
    }

    /**
     * @param list<string> $extraArgs
     */
    private static function buildUrlCall(string $src, array $extraArgs): string
    {
        $all = array_merge(['src: ' . $src], $extraArgs);
        return '\\Ynamite\\Media\\Image::url(' . implode(', ', $all) . ')';
    }

    /**
     * Filter attributes — collect into a single `filters: [...]` named arg.
     * `FilterParams::normalize` does server-side translation / clamping.
     * Returns null when no filter attributes are present (so the caller
     * can omit the arg entirely instead of emitting `filters: []`).
     */
    private static function buildFiltersArg(RexPic $self): ?string
    {
        $filterAttrs = [
            'brightness', 'contrast', 'gamma', 'sharpen', 'blur', 'pixelate',
            'filter', 'bg', 'border', 'flip', 'orient',
            'mark', 'marks', 'markw', 'markh', 'markpos', 'markpad', 'markalpha', 'markfit',
        ];
        $filterPairs = [];
        foreach ($filterAttrs as $key) {
            $val = $self->getParsedArg($key);
            if ($val !== null) {
                $filterPairs[] = "'" . $key . "' => " . $val;
            }
        }
        if ($filterPairs === []) {
            return null;
        }
        return 'filters: [' . implode(', ', $filterPairs) . ']';
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
