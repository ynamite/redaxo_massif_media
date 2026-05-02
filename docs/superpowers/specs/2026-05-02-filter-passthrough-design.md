# Glide filter passthrough — full manipulator surface

## Context

Today the addon exposes Glide's resize, format-conversion, crop, and color-profile manipulators. Everything else Glide ships — brightness, contrast, gamma, sharpen, blur, pixelate, color-preset filters (greyscale/sepia), background fill, border, flip, orient, watermark — is unreachable from the public API. Editors who want a black-and-white version of an image have to upload a separate b/w copy. Designers who want subtle sharpening on retina-rendered photos have no path.

This spec wires up the full Glide manipulator surface (11 simple filters + watermark with 8 sub-params) through `ImageBuilder`, `Image::picture`, and `REX_PIC`. Each filter contributes to the cache key so that variants are stored per-combination. Cache layout flips to **asset-keyed** so that all variants of a given source asset live in one directory — easier to inspect, easier to wipe per-asset, fewer top-level cache directories.

The addon is in pre-publish development, so backward-compatibility with the existing on-disk cache layout is a non-goal. Old cache files become orphaned by the layout change; `Cache leeren` clears them.

The intended outcome: `Image::for('hero.jpg')->width(1080)->ratio(16, 9)->sharpen(15)->bg('ffffff')->render()` works; `REX_PIC[src="hero.jpg" width="1080" ratio="16:9" filter="sepia" alpha="0.7"]` works; tests cover every new surface unit-first under the freshly landed PHPUnit infrastructure.

## API

### Builder methods (12 new on `ImageBuilder`)

| Method | Glide param | Type / range | Behavior |
|---|---|---|---|
| `brightness(int $v)` | `bri` | -100..100 | Clamped to range |
| `contrast(int $v)` | `con` | -100..100 | Clamped to range |
| `gamma(float $v)` | `gam` | 0.1..9.99 | Clamped to range |
| `sharpen(int $v)` | `sharp` | 0..100 | Clamped to range |
| `blur(int $v)` | `blur` | 0..100 | Clamped to range |
| `pixelate(int $v)` | `pixel` | 0..1000 | Clamped to range |
| `filter(string $preset)` | `filt` | `greyscale` \| `sepia` | Pass-through; Glide ignores unknown |
| `bg(string $hex)` | `bg` | 6 hex chars | Validated; invalid → drop param |
| `border(int $width, string $color, string $method = 'overlay')` | `border` | width > 0, hex color, `overlay`\|`shadow`\|`expand` | Composed into `{w},{color},{method}` string |
| `flip(string $axis)` | `flip` | `h` \| `v` \| `both` | Pass-through |
| `orient(int\|string $v)` | `orient` | `auto` \| `0` \| `90` \| `180` \| `270` | Pass-through |
| `watermark(string $src, ?float $size, ?int $width, ?int $height, string $position, int $padding, int $alpha, string $fit)` | `mark` family | composite | See "Watermark" below |

`Image::picture()` gains one new optional named arg: `array $filters = []`. The keys use friendly names (`brightness`, `contrast`, `bg`, `mark`, `markpos`, ...). The map's translation to Glide's short names (`bri`, `con`, `bg`, `mark`, `markpos`, ...) lives in `lib/Glide/FilterParams.php`.

`Pic::picture` inherits the new arg automatically (Pic extends Image).

### REX_PIC attributes (19 new)

Eleven simple-filter attributes plus eight watermark sub-params:

`brightness`, `contrast`, `gamma`, `sharpen`, `blur`, `pixelate`, `filter`, `bg`, `border`, `flip`, `orient`, `mark`, `marks`, `markw`, `markh`, `markpos`, `markpad`, `markalpha`, `markfit`.

Each attribute name maps directly to its builder counterpart. `border` accepts a comma-string `"{width},{color},{method}"`. Watermark uses individual attributes (REX_PIC syntax doesn't carry nested objects). `RexPic::getOutput` extends its existing per-key foreach loop to include the new attribute names.

### `lib/Glide/FilterParams.php` — central translation + validation

New file. A single static class with:

- `FRIENDLY_TO_GLIDE` — accepts BOTH friendly long names AND Glide short names as keys, mapping to the Glide short name as the canonical internal key. Examples: `'brightness' => 'bri'`, `'bri' => 'bri'`, `'mark' => 'mark'`, `'markpos' => 'markpos'`. This lets `Image::picture(filters: [...])` accept either style and lets REX_PIC pick the more natural attribute name per filter (long-form for color tweaks like `brightness`/`contrast`/`gamma`/`sharpen`/`blur`/`pixelate`; short-form for compact ones like `bg`/`filter`/`flip`/`orient`/`border` and the `mark*` watermark family).
- `RANGES` — per-numeric-param `[min, max]` tuples. Used by builder for clamping.
- `ENUMS` — per-enum-param valid value list. Used for validation logging only; values pass through to Glide either way.
- `validateHex(string $value): ?string` — returns the value if `/^[0-9a-f]{6}$/i`, else null.
- `clamp(string $glideParam, int|float $value): int|float` — applies the range table.
- `normalize(array $friendlyParams): array` — translates a friendly-keyed array to a Glide-keyed array, applying clamps and dropping invalid values. Returns the cleaned `$filterParams` ready for hashing.

`ImageBuilder` and `RexPic` both go through `FilterParams::normalize` so the surface is single-sourced.

## Cache-key encoding

### Asset-keyed cache layout (four shapes)

```
cache/{src}/{fmt}-{w}-{q}.{ext}                              # no crop, no filters
cache/{src}/{fmt}-{w}-{h}-{fitToken}-{q}.{ext}               # crop, no filters
cache/{src}/{fmt}-{w}-{q}-f{hash}.{ext}                      # no crop, with filters
cache/{src}/{fmt}-{w}-{h}-{fitToken}-{q}-f{hash}.{ext}       # crop, with filters
```

- `{src}` — the source asset's path relative to `rex_path::media()`, including subdirectories. The cache subdirectory mirrors the mediapool layout. Examples: `hero.jpg`, `gallery/2024/portrait.jpg`.
- `{fmt}` — output format (`avif`, `webp`, `jpg`).
- `{ext}` — output extension; matches `{fmt}`.
- `{fitToken}` — `cover-{focalX}-{focalY}` / `contain` / `stretch`, same as the existing crop spec.
- `{hash}` — `substr(md5(json_encode($filterParams, JSON_FORCE_OBJECT)), 0, 8)` after `ksort($filterParams)`. Deterministic regardless of insertion order.

Examples:

```
cache/hero.jpg/avif-1080-50.avif
cache/hero.jpg/avif-1080-1080-cover-50-50-50.avif
cache/portrait.jpg/jpg-800-450-contain-80-fA1B2C3D4.jpg
cache/gallery/2024/atelier.jpg/avif-1920-1920-cover-30-70-50.avif
```

### `Server::cachePath` (revised)

```php
public static function cachePath(string $path, array $params): string
{
    $fmt = strtolower((string) ($params['fm'] ?? pathinfo($path, PATHINFO_EXTENSION)));
    $w = (int) ($params['w'] ?? 0);
    $q = (int) ($params['q'] ?? 80);
    $h = isset($params['h']) ? (int) $params['h'] : null;
    $fitToken = isset($params['fit']) && is_string($params['fit']) && $params['fit'] !== ''
        ? $params['fit']
        : null;

    // Normalize Glide's `crop-X-Y` (cachePathCallable invocation) back to our
    // `cover-X-Y` (URL emission) so both call sites produce the same path.
    if ($fitToken !== null && str_starts_with($fitToken, 'crop-')) {
        $fitToken = 'cover-' . substr($fitToken, strlen('crop-'));
    }

    $filterParams = isset($params['filters']) && is_array($params['filters']) && $params['filters'] !== []
        ? $params['filters']
        : null;
    $hash = null;
    if ($filterParams !== null) {
        ksort($filterParams);
        $hash = substr(md5(json_encode($filterParams, JSON_FORCE_OBJECT)), 0, 8);
    }

    // Assemble transform spec (filename stem)
    $spec = sprintf('%s-%d', $fmt, $w);
    if ($h !== null && $h > 0 && $fitToken !== null) {
        $spec .= sprintf('-%d-%s', $h, $fitToken);
    }
    $spec .= '-' . $q;
    if ($hash !== null) {
        $spec .= '-f' . $hash;
    }

    return sprintf('%s/%s.%s', $path, $spec, $fmt);
}
```

The path returned is the asset-keyed cache path. Source asset's directory structure is preserved.

### `Endpoint::parseCachePath` (revised)

Splits at the **last** slash. Everything before is the source path; everything after is the transform-spec filename.

```php
public static function parseCachePath(string $path): ?array
{
    $lastSlash = strrpos($path, '/');
    if ($lastSlash === false) {
        return null;
    }
    $srcPath = substr($path, 0, $lastSlash);
    $filename = substr($path, $lastSlash + 1);

    $extPos = strrpos($filename, '.');
    if ($extPos === false) {
        return null;
    }
    $stem = substr($filename, 0, $extPos);
    $ext = strtolower(substr($filename, $extPos + 1));

    if (!preg_match('/^[a-z0-9]+$/', $ext)) {
        return null;
    }

    // Detect optional trailing `f{hash}` segment (8 hex chars).
    $tokens = explode('-', $stem);
    $hash = null;
    if (count($tokens) >= 4) {
        $last = $tokens[count($tokens) - 1];
        if (preg_match('/^f([a-f0-9]{8})$/', $last, $m)) {
            $hash = $m[1];
            array_pop($tokens);
        }
    }

    // Now parse the remaining tokens: fmt-w-q (legacy) or fmt-w-h-fitToken-q (crop).
    if (count($tokens) < 3) {
        return null;
    }

    $fmt = $tokens[0];
    if (!preg_match('/^[a-z0-9]+$/', $fmt)) {
        return null;
    }

    if (count($tokens) === 3 && ctype_digit($tokens[1]) && ctype_digit($tokens[2])) {
        return [
            'fmt' => $fmt, 'w' => (int) $tokens[1], 'q' => (int) $tokens[2],
            'h' => null, 'fit' => null, 'hash' => $hash, 'source' => $srcPath,
        ];
    }

    if (count($tokens) >= 5
        && ctype_digit($tokens[1])
        && ctype_digit($tokens[2])
        && ctype_digit($tokens[count($tokens) - 1])
    ) {
        $w = (int) $tokens[1];
        $h = (int) $tokens[2];
        $q = (int) $tokens[count($tokens) - 1];
        $fitParts = array_slice($tokens, 3, count($tokens) - 4);
        $fitToken = implode('-', $fitParts);
        if (!self::isValidFitToken($fitToken)) {
            return null;
        }
        return [
            'fmt' => $fmt, 'w' => $w, 'q' => $q, 'h' => $h, 'fit' => $fitToken,
            'hash' => $hash, 'source' => $srcPath,
        ];
    }

    return null;
}
```

The parser is robust to source paths with subdirectories (no special handling needed — `strrpos('/')` does the right thing) and to source filenames with extensions (e.g., `hero.jpg` becomes the dirname `hero.jpg`, not parsed for its `.jpg` suffix).

### HMAC scheme

`Signature::sign($cachePath, ?string $extraPayload = null)`:

```php
public static function sign(string $path, ?string $extraPayload = null, ?string $key = null): string
{
    $key ??= Config::signKey();
    $payload = $extraPayload !== null ? $path . '|' . $extraPayload : $path;
    return hash_hmac('sha256', $payload, $key);
}
```

`verify` mirrors. The `|` delimiter cannot appear in a base64url-encoded JSON blob, so concatenation is unambiguous.

URLs:

```
no filters:   /assets/addons/massif_media/cache/{path}?s={hmac}&v={mtime}
with filters: /assets/addons/massif_media/cache/{path}?s={hmac}&v={mtime}&f={base64url(json)}
```

`UrlBuilder::build` builds `&f` first (so the base64 payload is computed once), then signs `path|f` with `Signature::sign($cachePath, $f)`, then appends `?s=` and `&v=`.

### Endpoint flow on cache miss

1. Read `$_GET['p']` (path), `$_GET['s']` (sig), `$_GET['f']` (filters, optional, base64url-JSON).
2. Reconstruct signed payload: `$path` alone if `$_GET['f']` is empty, else `$path . '|' . $_GET['f']`. Verify HMAC against the sign key. Reject 403 on mismatch.
3. `parseCachePath($path)` extracts `fmt/w/q/h/fitToken/hash/source`.
4. If `$hash !== null`:
   - Decode `$_GET['f']` → filter array. If decoding fails, 400.
   - Recompute hash from the decoded array (same `ksort` + `md5` truncation). If it doesn't match `$hash` from the path, 400.
   - Translate `cover-X-Y` → `crop-X-Y` for `fitToken` (existing logic).
   - Set the `$filterParams` reference variable that's captured by Glide's `cachePathCallable` closure (so Glide computes the same on-disk path we're about to write to).
   - Build `makeImage` params: `['w' => …, 'q' => …, 'fm' => …, 'h' => …?, 'fit' => …?, ...$filterParams]`.
5. Generate, send, exit.

### Glide cachePathCallable thread-through

`Server::create` already sets `$server->setCachePathCallable(self::cachePathCallable())`. The closure now needs to know `$filterParams` so its internal `Server::cachePath` invocation produces the same on-disk path our URL points at.

Approach: the closure captures a `&$activeFilterParams` reference variable scoped to the `Server::create` call. `Endpoint::handle` calls a helper `Server::setActiveFilters($params)` before each `makeImage` call; the closure reads from the same static. Keeps the public closure signature unchanged.

```php
// Server.php
private static array $activeFilterParams = [];

public static function setActiveFilters(array $params): void
{
    self::$activeFilterParams = $params;
}

public static function cachePathCallable(): \Closure
{
    return fn (string $path, array $params): string => self::cachePath($path, [
        ...$params,
        'filters' => self::$activeFilterParams,
    ]);
}
```

Static state is acceptable here because `Endpoint::handle` is the only caller and it `exit`s after one request. `tearDown` in tests resets `$activeFilterParams` to `[]`.

## Validation

| Filter category | Behavior on invalid input |
|---|---|
| Numeric ranges | Clamp silently to `[min, max]`. `brightness(200)` → `100`. |
| Enum strings (`filter`, `flip`, `orient`, `border` method, `markpos`, `markfit`) | Pass through to Glide; Glide silently ignores unrecognized values. We log via `rex_logger` only when the value isn't in our known-good list (debug-level signal, no exception). |
| Hex colors (`bg`, `border` color) | Validate `/^[0-9a-f]{6}$/i`. Invalid → drop the param entirely (don't pass to Glide). Logged at debug. |
| File paths (`watermark` `mark`) | No upfront validation. If Glide can't read the file at generation time, the cache miss returns 404 and logs the underlying `Throwable`. Subsequent retries with a different `mark` produce a cache miss for the new combination. |

Tests exercise each category. Validation logic is single-sourced in `FilterParams::normalize`.

## Watermark

`watermark(string $src, ?float $size, ?int $width, ?int $height, string $position, int $padding, int $alpha, string $fit)` writes to `$filterParams` under Glide's keys: `mark`, `marks`, `markw`, `markh`, `markpos`, `markpad`, `markalpha`, `markfit`.

- `$src` is the only required arg; everything else has a sensible default.
- Validation: `$size` clamped to `0.0..1.0`; `$alpha` clamped to `0..100`; `$padding >= 0`; `$position` and `$fit` pass-through (Glide validates internally).
- `$src` is treated as a mediapool-relative path. If Glide can't resolve it at generation time, the variant fails as described above.

REX_PIC equivalent — the 8 attributes map 1:1 to the named args via the `FilterParams::FRIENDLY_TO_GLIDE` table.

## Critical files to modify or create

- `lib/Glide/FilterParams.php` — **new**. Translation map + range table + clamping + hex validation + `normalize()`.
- `lib/Builder/ImageBuilder.php` — 12 new setter methods, `private array $filterParams`, threaded into `PictureRenderer::render` call.
- `lib/Image.php` — `picture()` gains `array $filters = []` named arg; passed to `ImageBuilder::filters()` (a new method that bulk-applies via `FilterParams::normalize`).
- `lib/Pic.php` — inherits via `extends Image`; no change.
- `lib/Var/RexPic.php` — extends the per-key foreach loop with the 19 new attributes; passes them as a `filters` keyed array to `Image::picture`.
- `lib/View/PictureRenderer.php` — accepts `array $filterParams = []`; passes through to `UrlBuilder::build` and to `Preloader::queue`.
- `lib/Pipeline/UrlBuilder.php` — accepts `array $filterParams = []`; computes hash, embeds in cache path via `Server::cachePath`, builds `&f` query param, signs `path|f`. CDN template gains `{f}` token.
- `lib/Pipeline/Preloader.php` — preserves `filterParams` in queued entries; emits preload URLs with `&f=` so preloads hit the same cache as the rendered `<img>`.
- `lib/Glide/Server.php` — `cachePath()` reshape per Section 2 (asset-keyed, optional `f{hash}` suffix, optional `h-fitToken` insertion); `cachePathCallable()` reads `self::$activeFilterParams`; `setActiveFilters()` mutator.
- `lib/Glide/Endpoint.php` — `parseCachePath()` reshape (split-on-last-slash, transform-from-stem); `handle()` decodes `&f`, recomputes hash, calls `Server::setActiveFilters($params)` before `makeImage`.
- `lib/Glide/Signature.php` — `sign()` and `verify()` accept `?string $extraPayload = null` second arg.
- `assets/cache/.gitignore` — **new**. `*\n!.gitignore`.
- `README.md` — new "Filter" section with attribute table; new "Watermark" subsection; URL-Schema section reflects the asset-keyed layout + `f{hash}` suffix.
- `CHANGELOG.md` — Added (full filter surface), Changed (cache layout flips to asset-keyed; URL gains `&f=` for filters; HMAC covers path|filterblob), Notes (run Cache leeren post-upgrade for cleanliness).
- `CLAUDE.md` — note new cache layout shape; note `Server::setActiveFilters` static-state pattern (single-caller, `exit`-bounded — acceptable but call out for future contributors).

## Reused functions / utilities

- `MetadataReader::normalizeFocal` and `formatFocal` — unchanged, already pure helpers, focal point still feeds the `cover-X-Y` token via the existing path.
- `kornrunner/blurhash` — untouched. Filters don't affect blurhash generation (which runs on the original source).
- `Pipeline/Placeholder` — untouched. LQIP runs on the source, not the filtered output.
- `Endpoint::isValidFitToken`, `Endpoint::stripFormatExtension`, `Endpoint::mimeFor` — refactored slightly to operate on the new path shape but retain the same validation rules.

## Out of scope

- LQIP rendered with filters applied (placeholder strategy is independent of per-render filter intent).
- A "preset" abstraction (named, saved filter combinations users reuse by name) — possible v2.
- Custom Glide manipulators beyond what `league/glide` ships.
- Filter-aware cache invalidation (clearing only filtered variants while preserving unfiltered — `CACHE_DELETED` still wipes everything).
- File-existence pre-validation for watermark paths.
- The placeholder pipeline cleanup bugs (LQIP gating, settings-page text) — listed in `docs/superpowers/parking-lot.md`.

## Verification

Verification rests on the test suite landing in the testing-infrastructure spec.

**Unit-suite assertions** (representative, not exhaustive):
- `FilterParamsTest::testFriendlyToGlideRoundTrip` — every documented friendly name maps to a Glide key.
- `FilterParamsTest::testClampsBrightness` — `clamp('bri', 200)` returns `100`.
- `FilterParamsTest::testRejectsInvalidHex` — `validateHex('xyz')` returns null.
- `FilterParamsTest::testNormalizeDropsInvalidHex` — `normalize(['bg' => 'xyz'])` returns `[]`.
- `ServerTest::testCachePathFourShapes` — verifies the four cache-path shapes against fixed input.
- `ServerTest::testCachePathHashIsDeterministic` — same filter set in different insertion orders produces the same hash.
- `ServerTest::testCachePathPreservesSourceSubdirectories` — `gallery/2024/hero.jpg` survives intact in the cache path.
- `EndpointTest::testParseCachePathFourShapes` — round-trip with `Server::cachePath` for each shape.
- `EndpointTest::testParseCachePathRejectsBogusHash` — `f{not-8-hex}` returns null.
- `SignatureTest::testSignVerifyWithExtraPayload` — sign+verify with a filter blob; tampered blob fails.
- `ImageBuilderTest::testBrightnessClamps`, `testBgValidatesHex`, `testWatermarkComposesAllSubParams`.
- `RexPicTest::testFitAttributePassesThrough`, plus equivalent for each new filter attribute.

**Integration-suite assertions**:
- `FilterPipelineTest::testGreyscaleFilterProducesGreyscaleVariant` — render with `filter: 'greyscale'`, decode the resulting cache file, assert pixels are desaturated (compare R/G/B channel variance to a threshold).
- `FilterPipelineTest::testWatermarkAppearsInOutput` — render with a fixture watermark; decode the cache file; assert the watermark's color signature is detectable in the expected position.
- `FilterPipelineTest::testTamperedFilterBlobRejected` — emit a URL via UrlBuilder, mutate the `&f` payload, hit Endpoint, expect 403.
- `FilterPipelineTest::testCacheHitFastpath` — first request generates the variant; second request to the same URL returns the same body without invoking PHP (assertable via instrumented log).

**Bug-injection sanity** (one-time, recorded in plan): remove the `ksort` from `Server::cachePath` (making the hash insertion-order-dependent); the determinism unit test must fail. Restore.

**Live Herd verification** (manual, one-time): apply every filter category once via `REX_PIC` on `viterex-installer-default.test`, confirm cache files land at the new asset-keyed paths with the expected `f{hash}` suffix when filters are present.
