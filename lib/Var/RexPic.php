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

        // Art direction: JSON-encoded list of variants in the `art` attribute.
        // Use getArg (NOT getParsedArg) — the value is bare JSON, not a PHP
        // literal. Bad JSON / unknown keys log a warning and degrade
        // gracefully (picture renders without art direction; broken JSON in
        // editor input shouldn't 500 the page).
        $artLiteral = self::buildArtArg($this);
        if ($artLiteral !== null) {
            $args[] = 'art: ' . $artLiteral;
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

    /**
     * Build the PHP array-literal for the `art:` named arg from the slice's
     * `art` attribute (JSON). Returns null when the attribute is absent,
     * empty, or invalid JSON / wrong shape — caller omits the named arg
     * entirely (renders without art direction).
     *
     * Three accepted JSON shapes (all decode to the same internal list):
     *   1. comma-separated bare variants — `{"media":"…","src":"…"},{"media":"…"}`.
     *      Idiomatic in slice content (looks like a list without the `[]` that
     *      REDAXO's tokenizer can't handle). Resurrected via `[…]` wrap when
     *      the primary parse fails.
     *   2. object keyed by id — `{"sm":{"media":"…"},"md":{"media":"…"}}`.
     *      Keys are free-form identifiers; values are variant objects. Order
     *      preserved by PHP's `json_decode`. Distinguished from a single bare
     *      variant by checking whether the FIRST value is itself an array.
     *   3. single bare variant — `{"media":"…","src":"…"}` (one breakpoint
     *      only). Detected when the decoded object's first value is scalar.
     *   4. list — `[{"media":"…"},{"media":"…"}]`. Works for direct PHP calls
     *      (`Image::picture(art: […])`); breaks in `REX_PIC[art='[…]']`
     *      because REDAXO's `rex_var` tokenizer regex forbids unescaped `[`/`]`
     *      inside a REX_VAR tag (`var.php::getMatches`). Accepted here for
     *      symmetry with the PHP API.
     *
     * Emits an array literal (NOT an `ArtDirectionVariant` constructor call)
     * so the cached PHP survives a hypothetical class rename or namespace
     * move. `Image::picture` re-validates via `ArtDirectionVariant::fromArray`
     * at runtime; this side just sanitises the keys + value types.
     */
    private static function buildArtArg(RexPic $self): ?string
    {
        $raw = $self->getArg('art');
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = self::decodeArtJson($raw);
        if (!is_array($decoded) || $decoded === []) {
            return null;
        }

        // Parent src is guaranteed present here — getOutput() returns false
        // before calling buildArtArg if the picture has no src. Captured raw
        // (not parsed) so it's a JSON-safe string; nested REX_VARs in the
        // parent src are not propagated into the variant — explicit per-variant
        // src is required for that.
        $parentSrc = $self->getArg('src');
        $parentSrcFallback = is_string($parentSrc) && trim($parentSrc) !== '' ? $parentSrc : null;

        $allowedKeys = ['media', 'src', 'width', 'height', 'ratio', 'focal', 'fit', 'filters'];
        $clean = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $row = [];
            foreach ($allowedKeys as $key) {
                if (array_key_exists($key, $entry)) {
                    $row[$key] = $entry[$key];
                }
            }
            // src auto-inherits from parent picture when omitted or blank —
            // common for "same image, different crop/focal per breakpoint".
            // Treat empty string as "use default" (friendlier than skipping).
            if ((!array_key_exists('src', $row)
                    || !is_string($row['src'])
                    || trim($row['src']) === '')
                && $parentSrcFallback !== null
            ) {
                $row['src'] = $parentSrcFallback;
            }
            // media + src are required
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
        if ($clean === []) {
            return null;
        }

        // Re-encode as JSON and emit `json_decode(<json>, true)` in the
        // cached PHP. var_export handles the JSON-string-as-PHP-string
        // escaping (single quotes / backslashes), and Image::picture()
        // re-validates each entry through ArtDirectionVariant::fromArray
        // at runtime. Avoids fragile var_export-of-array-with-strings
        // post-processing (e.g., a media query containing `)` would corrupt
        // a naive long-array → short-array regex rewrite).
        $json = json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return null;
        }
        return 'json_decode(' . var_export($json, true) . ', true)';
    }

    /**
     * Decode the raw `art` attribute string into a list of variant arrays.
     *
     * Tries shapes in this order:
     *   1. as-typed JSON (object keyed by id, list, or single bare variant)
     *   2. `[…]`-wrapped — rescues comma-separated bare variants like
     *      `{"m":…},{"m":…}` (the most natural slice-content shorthand;
     *      not valid JSON without an outer wrapper, but the outer `[…]`
     *      can't be in the source because REDAXO's tokenizer bars it).
     *
     * Single-bare-variant detection: if the decoded object's first value is
     * scalar (e.g. `{"media":"…","src":"…"}` — looks like an object map but
     * is actually one variant), wrap as a 1-elem list so the per-entry
     * loop sees one variant rather than treating each scalar key/value pair
     * as a separate (invalid) entry.
     *
     * Returns null on parse failure (logged via rex_logger so editors can
     * find the typo in the system log) or empty input.
     *
     * @return list<array<string, mixed>>|null
     */
    private static function decodeArtJson(string $raw): ?array
    {
        try {
            $decoded = json_decode($raw, true, depth: 4, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $primaryError) {
            try {
                $decoded = json_decode('[' . $raw . ']', true, depth: 4, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                \rex_logger::factory()->log(
                    'warning',
                    'massif_media: REX_PIC art attr JSON parse failed: ' . $primaryError->getMessage(),
                );
                return null;
            }
        }

        if (!is_array($decoded) || $decoded === []) {
            return null;
        }

        // Single-bare-variant: object whose first value is scalar.
        $first = $decoded[array_key_first($decoded)];
        if (!is_array($first)) {
            return [$decoded];
        }

        // List or keyed map → strip keys.
        return array_values($decoded);
    }
}
