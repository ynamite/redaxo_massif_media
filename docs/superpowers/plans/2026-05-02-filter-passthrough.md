# Glide Filter Passthrough Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. **Prerequisite: the testing-infrastructure plan (`2026-05-02-testing-infrastructure.md`) must be implemented first** — this plan develops test-first under the PHPUnit suite that lands there.

**Goal:** Wire up Glide's full manipulator surface (brightness, contrast, gamma, sharpen, blur, pixelate, filter preset, bg, border, flip, orient, watermark) through `ImageBuilder`, `Image::picture`, and `REX_PIC`. Flip cache layout to asset-keyed (`cache/{src}/{transform}.{ext}`) so all variants of one source live in one directory. Filters contribute to a deterministic 8-hex hash; full filter values ride in an HMAC-signed `&f=` URL query param.

**Architecture:** `FilterParams` is a new central translation/validation class (friendly name ↔ Glide short name, range table, hex validation, `normalize()`). `ImageBuilder` gains 12 typed setters + an internal `array $filterParams`. `UrlBuilder` computes the filter hash, embeds it in the cache filename, ships the actual filter values in `&f=base64url(json)`, and signs `path|f` together via the new `Signature::sign($path, $extraPayload)` second arg. `Endpoint::handle` decodes `&f`, recomputes the hash to validate, and threads filters into Glide's `makeImage`. The Glide `cachePathCallable` reads filter params from a `Server::$activeFilterParams` static (set by Endpoint before each `makeImage` call) so on-disk cache paths match emitted URLs even when Glide internally calls `Server::cachePath` with translated params.

**Tech Stack:** PHP 8.2+, league/glide ^3, PHPUnit ^11 (already landed via testing-infra plan). Backward compatibility with existing on-disk cache layout is a **non-goal** (addon is pre-publish).

---

## File structure

| File | Purpose | Action |
|---|---|---|
| `lib/Glide/FilterParams.php` | translation map + range table + clamping + hex validation + `normalize()` | Create |
| `lib/Glide/Server.php` | `cachePath` asset-keyed reshape; `cachePathCallable` reads `$activeFilterParams`; `setActiveFilters()` mutator | Modify |
| `lib/Glide/Endpoint.php` | `parseCachePath` asset-keyed reshape; `handle()` decodes `&f`, recomputes hash, calls `setActiveFilters` | Modify |
| `lib/Glide/Signature.php` | `sign`/`verify` accept `?string $extraPayload` second arg | Modify |
| `lib/Pipeline/UrlBuilder.php` | accept `array $filterParams`; build `&f` blob; sign `path\|f`; CDN template gains `{f}` token | Modify |
| `lib/Pipeline/Preloader.php` | preserve `filterParams` in queue entries; thread to URL building | Modify |
| `lib/View/PictureRenderer.php` | accept `array $filterParams` arg; thread to UrlBuilder + Preloader | Modify |
| `lib/Builder/ImageBuilder.php` | 12 new setter methods + `private array $filterParams`; `filters(array)` bulk-applier; pass to renderer | Modify |
| `lib/Image.php` | `picture()` gains `array $filters = []` arg | Modify |
| `lib/Var/RexPic.php` | extend per-key foreach with 19 new attributes; pass via `filters` keyed array to `Image::picture` | Modify |
| `assets/cache/.gitignore` | `*\n!.gitignore` | Create |
| `tests/Unit/Glide/FilterParamsTest.php` | translation + clamping + hex validation + normalize | Create |
| `tests/Unit/Glide/ServerTest.php` | UPDATE expected paths to asset-keyed shape; add filter-hash test | Modify |
| `tests/Unit/Glide/EndpointTest.php` | UPDATE parseCachePath expected paths; add filter-shape parsing tests | Modify |
| `tests/Unit/Glide/SignatureTest.php` | add extraPayload tests | Modify |
| `tests/Unit/Builder/ImageBuilderTest.php` | new file: setter methods, clamping, hex validation, watermark | Create |
| `tests/Unit/Var/RexPicTest.php` | extend with filter-attribute passthrough tests | Modify |
| `tests/Integration/CropPipelineTest.php` | UPDATE expected cache paths to asset-keyed; remains otherwise unchanged | Modify |
| `tests/Integration/HmacRoundtripTest.php` | extend with extraPayload roundtrip + tampered-blob rejection | Modify |
| `tests/Integration/FilterPipelineTest.php` | new file: real Glide with filters applied, full URL emission → cache miss → variant generated | Create |
| `README.md` | new "Filter" section + "Watermark" subsection + URL-Schema reflects asset-keyed layout + `f{hash}` | Modify |
| `CHANGELOG.md` | Added (full filter surface), Changed (cache layout asset-keyed; URL gains `&f=`; HMAC covers `path\|f`) | Modify |
| `CLAUDE.md` | note new cache layout shape + `Server::setActiveFilters` static-state pattern | Modify |

---

### Task 1: Create `FilterParams` translation/validation class

**Files:**
- Create: `lib/Glide/FilterParams.php`
- Create: `tests/Unit/Glide/FilterParamsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Glide;

use PHPUnit\Framework\TestCase;
use Ynamite\Media\Glide\FilterParams;

final class FilterParamsTest extends TestCase
{
    public function testNormalizeTranslatesFriendlyNames(): void
    {
        $out = FilterParams::normalize(['brightness' => 10, 'contrast' => 5]);
        self::assertSame(['bri' => 10, 'con' => 5], $out);
    }

    public function testNormalizeAcceptsGlideShortNamesToo(): void
    {
        $out = FilterParams::normalize(['bri' => 10, 'mark' => 'logo.png']);
        self::assertSame(['bri' => 10, 'mark' => 'logo.png'], $out);
    }

    public function testNormalizeClampsBrightness(): void
    {
        self::assertSame(['bri' => 100], FilterParams::normalize(['brightness' => 200]));
        self::assertSame(['bri' => -100], FilterParams::normalize(['brightness' => -300]));
    }

    public function testNormalizeClampsGammaAsFloat(): void
    {
        $out = FilterParams::normalize(['gamma' => 15.0]);
        self::assertSame(['gam' => 9.99], $out);
    }

    public function testNormalizeDropsInvalidHex(): void
    {
        self::assertSame([], FilterParams::normalize(['bg' => 'xyz']));
        self::assertSame(['bg' => 'ffffff'], FilterParams::normalize(['bg' => 'ffffff']));
        self::assertSame(['bg' => 'ffffff'], FilterParams::normalize(['bg' => 'FFFFFF']));
    }

    public function testNormalizeDropsUnknownKeys(): void
    {
        self::assertSame(['bri' => 10], FilterParams::normalize(['brightness' => 10, 'bogus' => 'value']));
    }

    public function testValidateHexAcceptsSixHexChars(): void
    {
        self::assertSame('ff00cc', FilterParams::validateHex('ff00cc'));
        self::assertSame('FF00CC', FilterParams::validateHex('FF00CC'));
        self::assertNull(FilterParams::validateHex('xyz'));
        self::assertNull(FilterParams::validateHex('#ffffff'));
        self::assertNull(FilterParams::validateHex('fff'));
    }

    public function testClampNumericRanges(): void
    {
        self::assertSame(50, FilterParams::clamp('bri', 50));
        self::assertSame(100, FilterParams::clamp('bri', 200));
        self::assertSame(-100, FilterParams::clamp('bri', -200));
        self::assertSame(0.5, FilterParams::clamp('gam', 0.5));
        self::assertSame(0.1, FilterParams::clamp('gam', 0.0));
        self::assertSame(9.99, FilterParams::clamp('gam', 100));
    }
}
```

- [ ] **Step 2: Run, expect failure (class doesn't exist)**

```bash
vendor/bin/phpunit --filter=FilterParamsTest
```
Expected: `Error: Class "Ynamite\Media\Glide\FilterParams" not found`.

- [ ] **Step 3: Create `lib/Glide/FilterParams.php`**

```php
<?php

declare(strict_types=1);

namespace Ynamite\Media\Glide;

/**
 * Central translation + validation surface for Glide filter passthrough.
 *
 * - FRIENDLY_TO_GLIDE accepts both friendly long names (brightness) AND
 *   Glide short names (bri) as keys; both map to the Glide short name.
 * - RANGES gives [min, max] per numeric param for clamping.
 * - normalize() takes a user-supplied keyed array and returns a clean array
 *   keyed by Glide short name with values clamped/validated/dropped.
 */
final class FilterParams
{
    public const FRIENDLY_TO_GLIDE = [
        // long-form ↔ short-form
        'brightness' => 'bri',
        'contrast'   => 'con',
        'gamma'      => 'gam',
        'sharpen'    => 'sharp',
        'blur'       => 'blur',
        'pixelate'   => 'pixel',
        // identity entries (so callers can use either name)
        'bri'        => 'bri',
        'con'        => 'con',
        'gam'        => 'gam',
        'sharp'      => 'sharp',
        'pixel'      => 'pixel',
        // pass-through string filters
        'filter'     => 'filt',
        'filt'       => 'filt',
        'flip'       => 'flip',
        'orient'     => 'orient',
        'border'     => 'border',
        'bg'         => 'bg',
        // watermark family
        'mark'       => 'mark',
        'marks'      => 'marks',
        'markw'      => 'markw',
        'markh'      => 'markh',
        'markpos'    => 'markpos',
        'markpad'    => 'markpad',
        'markalpha'  => 'markalpha',
        'markfit'    => 'markfit',
    ];

    public const RANGES = [
        'bri'       => [-100, 100],
        'con'       => [-100, 100],
        'gam'       => [0.1, 9.99],
        'sharp'     => [0, 100],
        'blur'      => [0, 100],
        'pixel'     => [0, 1000],
        'marks'     => [0.0, 1.0],
        'markalpha' => [0, 100],
    ];

    /** Glide params whose values are hex colors. */
    public const HEX_PARAMS = ['bg'];

    /**
     * Translate friendly-keyed array to Glide-keyed array, applying clamps and
     * dropping invalid entries. Output is ready for the cache-key hash.
     *
     * @param array<string, scalar> $params
     * @return array<string, scalar>
     */
    public static function normalize(array $params): array
    {
        $out = [];
        foreach ($params as $key => $value) {
            $glideKey = self::FRIENDLY_TO_GLIDE[$key] ?? null;
            if ($glideKey === null) {
                continue;
            }

            if (in_array($glideKey, self::HEX_PARAMS, true)) {
                $hex = self::validateHex((string) $value);
                if ($hex !== null) {
                    $out[$glideKey] = $hex;
                }
                continue;
            }

            if (isset(self::RANGES[$glideKey])) {
                $out[$glideKey] = self::clamp($glideKey, $value);
                continue;
            }

            $out[$glideKey] = $value;
        }
        return $out;
    }

    public static function validateHex(string $value): ?string
    {
        return preg_match('/^[0-9a-f]{6}$/i', $value) === 1 ? $value : null;
    }

    public static function clamp(string $glideParam, int|float $value): int|float
    {
        $range = self::RANGES[$glideParam] ?? null;
        if ($range === null) {
            return $value;
        }
        [$min, $max] = $range;
        // Preserve int-vs-float by inferring from the range's bounds.
        if (is_int($min) && is_int($max)) {
            return max($min, min($max, (int) $value));
        }
        return max((float) $min, min((float) $max, (float) $value));
    }
}
```

- [ ] **Step 4: Run, expect pass**

```bash
vendor/bin/phpunit --filter=FilterParamsTest
```
Expected: `OK (8 tests, ~25 assertions)`.

- [ ] **Step 5: Commit**

```bash
git add lib/Glide/FilterParams.php tests/Unit/Glide/FilterParamsTest.php
git commit -m "$(cat <<'EOF'
Add Glide\\FilterParams: translation + validation surface

Single static class that's the source of truth for the
filter-passthrough feature. FRIENDLY_TO_GLIDE accepts both long
names (brightness) and Glide short names (bri); RANGES gives
[min, max] per numeric param for clamping; HEX_PARAMS lists params
whose values must validate as 6-hex-char colors.

normalize() takes a user-supplied array and returns a Glide-keyed
array with numerics clamped, hex colors validated (invalid → drop),
and unknown keys dropped. Output is ready for the cache-key hash.

8 unit tests cover translation, clamping (int and float ranges),
hex validation, unknown-key dropping, and the friendly-or-short
name acceptance.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Reshape cache layout to asset-keyed (`Server::cachePath` + `Endpoint::parseCachePath` + tests)

**Files:**
- Modify: `lib/Glide/Server.php`
- Modify: `lib/Glide/Endpoint.php`
- Modify: `tests/Unit/Glide/ServerTest.php`
- Modify: `tests/Unit/Glide/EndpointTest.php`
- Modify: `tests/Integration/CropPipelineTest.php` (one expected-path assertion)

This task is the load-bearing change: it flips the cache layout from `{transformParams}/{src}.{ext}` to `{src}/{transformParams}.{ext}` AND adds the optional `f{hash}` filter segment. Updates tests first (TDD red), then production code (green).

- [ ] **Step 1: Update `tests/Unit/Glide/ServerTest.php` with new expected paths**

Replace the test methods with:

```php
<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Glide;

use PHPUnit\Framework\TestCase;
use Ynamite\Media\Glide\Server;

final class ServerTest extends TestCase
{
    public function testCachePathLegacyShape(): void
    {
        $path = Server::cachePath('hero.jpg', ['fm' => 'avif', 'w' => 1080, 'q' => 50]);
        self::assertSame('hero.jpg/avif-1080-50.avif', $path);
    }

    public function testCachePathCropShape(): void
    {
        $path = Server::cachePath('hero.jpg', [
            'fm' => 'avif', 'w' => 1080, 'q' => 50,
            'h' => 1080, 'fit' => 'cover-50-50',
        ]);
        self::assertSame('hero.jpg/avif-1080-1080-cover-50-50-50.avif', $path);
    }

    public function testCachePathContainShape(): void
    {
        $path = Server::cachePath('hero.jpg', [
            'fm' => 'webp', 'w' => 800, 'q' => 75,
            'h' => 600, 'fit' => 'contain',
        ]);
        self::assertSame('hero.jpg/webp-800-600-contain-75.webp', $path);
    }

    public function testCachePathRoundTripsCoverCrop(): void
    {
        $coverSide = Server::cachePath('hero.jpg', [
            'fm' => 'avif', 'w' => 1080, 'q' => 50,
            'h' => 1080, 'fit' => 'cover-50-50',
        ]);
        $cropSide = Server::cachePath('hero.jpg', [
            'fm' => 'avif', 'w' => 1080, 'q' => 50,
            'h' => 1080, 'fit' => 'crop-50-50',
        ]);
        self::assertSame($coverSide, $cropSide);
    }

    public function testCachePathPreservesSourceSubdirs(): void
    {
        $path = Server::cachePath('gallery/2024/atelier.jpg', [
            'fm' => 'avif', 'w' => 1920, 'q' => 50,
            'h' => 1920, 'fit' => 'cover-30-70',
        ]);
        self::assertSame('gallery/2024/atelier.jpg/avif-1920-1920-cover-30-70-50.avif', $path);
    }

    public function testCachePathWithFiltersAppendsHash(): void
    {
        $path = Server::cachePath('hero.jpg', [
            'fm' => 'jpg', 'w' => 800, 'q' => 80,
            'filters' => ['bri' => 10, 'sharp' => 20],
        ]);
        self::assertMatchesRegularExpression('@^hero\.jpg/jpg-800-80-f[a-f0-9]{8}\.jpg$@', $path);
    }

    public function testCachePathFilterHashIsDeterministic(): void
    {
        // Same filters in different insertion order must produce the same hash.
        $a = Server::cachePath('hero.jpg', [
            'fm' => 'jpg', 'w' => 800, 'q' => 80,
            'filters' => ['bri' => 10, 'sharp' => 20, 'con' => 5],
        ]);
        $b = Server::cachePath('hero.jpg', [
            'fm' => 'jpg', 'w' => 800, 'q' => 80,
            'filters' => ['con' => 5, 'sharp' => 20, 'bri' => 10],
        ]);
        self::assertSame($a, $b);
    }

    public function testCachePathCropAndFilters(): void
    {
        $path = Server::cachePath('hero.jpg', [
            'fm' => 'avif', 'w' => 1080, 'q' => 50,
            'h' => 1080, 'fit' => 'cover-50-50',
            'filters' => ['filt' => 'sepia'],
        ]);
        self::assertMatchesRegularExpression(
            '@^hero\.jpg/avif-1080-1080-cover-50-50-50-f[a-f0-9]{8}\.avif$@',
            $path,
        );
    }
}
```

- [ ] **Step 2: Update `tests/Unit/Glide/EndpointTest.php` with new expected shapes**

Replace:

```php
<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Glide;

use PHPUnit\Framework\TestCase;
use Ynamite\Media\Glide\Endpoint;

final class EndpointTest extends TestCase
{
    public function testParseCachePathLegacyShape(): void
    {
        $parsed = Endpoint::parseCachePath('hero.jpg/avif-1080-50.avif');
        self::assertNotNull($parsed);
        self::assertSame('hero.jpg', $parsed['source']);
        self::assertSame('avif', $parsed['fmt']);
        self::assertSame(1080, $parsed['w']);
        self::assertSame(50, $parsed['q']);
        self::assertNull($parsed['h']);
        self::assertNull($parsed['fit']);
        self::assertNull($parsed['hash']);
    }

    public function testParseCachePathCropShape(): void
    {
        $parsed = Endpoint::parseCachePath('hero.jpg/avif-1080-1080-cover-50-50-50.avif');
        self::assertNotNull($parsed);
        self::assertSame('hero.jpg', $parsed['source']);
        self::assertSame(1080, $parsed['h']);
        self::assertSame('cover-50-50', $parsed['fit']);
        self::assertNull($parsed['hash']);
    }

    public function testParseCachePathFilterShape(): void
    {
        $parsed = Endpoint::parseCachePath('hero.jpg/jpg-800-80-fa1b2c3d4.jpg');
        self::assertNotNull($parsed);
        self::assertSame('a1b2c3d4', $parsed['hash']);
        self::assertNull($parsed['fit']);
    }

    public function testParseCachePathCropAndFilters(): void
    {
        $parsed = Endpoint::parseCachePath('hero.jpg/avif-1080-1080-contain-50-fa1b2c3d4.avif');
        self::assertNotNull($parsed);
        self::assertSame('contain', $parsed['fit']);
        self::assertSame('a1b2c3d4', $parsed['hash']);
    }

    public function testParseCachePathPreservesSubdirSource(): void
    {
        $parsed = Endpoint::parseCachePath('gallery/2024/atelier.jpg/avif-1920-50.avif');
        self::assertNotNull($parsed);
        self::assertSame('gallery/2024/atelier.jpg', $parsed['source']);
    }

    public function testParseCachePathRejectsMalformed(): void
    {
        self::assertNull(Endpoint::parseCachePath('garbage'));
        self::assertNull(Endpoint::parseCachePath('hero.jpg/avif-x-50.avif'));
        self::assertNull(Endpoint::parseCachePath('hero.jpg/avif-1080-1080-bogus-50.avif'));
        self::assertNull(Endpoint::parseCachePath('no-extension'));
    }

    public function testParseCachePathRejectsBogusHash(): void
    {
        // Hash must be 8 lowercase hex chars.
        self::assertNull(Endpoint::parseCachePath('hero.jpg/jpg-800-80-fNOTHEX.jpg'));
        self::assertNull(Endpoint::parseCachePath('hero.jpg/jpg-800-80-fab.jpg'));
    }
}
```

- [ ] **Step 3: Update `tests/Integration/CropPipelineTest.php`**

Find the assertion in `testCachePathContainsCoverFitToken`:

```php
self::assertStringContainsString('cover-50-50', $rel);
```

This still works (the substring check is layout-agnostic). But also verify the full new shape format. Add immediately after that assertion:

```php
self::assertMatchesRegularExpression('@^hero\.jpg/jpg-400-400-cover-50-50-80\.jpg$@', $rel);
```

- [ ] **Step 4: Run all tests, expect failures across the board**

```bash
vendor/bin/phpunit
```
Expected: many failures in `ServerTest`, `EndpointTest`, `CropPipelineTest` because production code still emits the old transform-first layout.

- [ ] **Step 5: Update `Server::cachePath` in `lib/Glide/Server.php`**

Replace the `cachePath` method:

```php
    /**
     * Compute the cache path for a given source + params.
     *
     * Asset-keyed: {src}/{transformSpec}.{ext}.
     *
     * Four shapes for the transform spec:
     *   {fmt}-{w}-{q}                                  — no crop, no filters
     *   {fmt}-{w}-{h}-{fitToken}-{q}                   — crop, no filters
     *   {fmt}-{w}-{q}-f{hash}                          — no crop, with filters
     *   {fmt}-{w}-{h}-{fitToken}-{q}-f{hash}           — crop, with filters
     *
     * fitToken is `cover-{X}-{Y}` / `contain` / `stretch`.
     * Glide's `crop-X-Y` (passed in when invoked from inside makeImage via the
     * cachePathCallable) is normalized back to our `cover-X-Y` form.
     */
    public static function cachePath(string $path, array $params): string
    {
        $fmt = strtolower((string) ($params['fm'] ?? pathinfo($path, PATHINFO_EXTENSION)));
        $w = (int) ($params['w'] ?? 0);
        $q = (int) ($params['q'] ?? 80);
        $h = isset($params['h']) ? (int) $params['h'] : null;
        $fitToken = isset($params['fit']) && is_string($params['fit']) && $params['fit'] !== ''
            ? $params['fit']
            : null;

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

- [ ] **Step 6: Update `Endpoint::parseCachePath` in `lib/Glide/Endpoint.php`**

Replace the `parseCachePath` method:

```php
    /**
     * Parse asset-keyed cache path back into its components.
     *
     * Path shape: {src}/{transformSpec}.{ext}, with transformSpec being one of:
     *   - {fmt}-{w}-{q}                              — legacy (no crop, no filters)
     *   - {fmt}-{w}-{h}-{fitToken}-{q}               — crop, no filters
     *   - {fmt}-{w}-{q}-f{hash}                      — no crop, with filters
     *   - {fmt}-{w}-{h}-{fitToken}-{q}-f{hash}       — crop, with filters
     *
     * @return array{fmt: string, w: int, q: int, h: int|null, fit: string|null, hash: string|null, source: string}|null
     */
    public static function parseCachePath(string $path): ?array
    {
        $lastSlash = strrpos($path, '/');
        if ($lastSlash === false) {
            return null;
        }
        $srcPath = substr($path, 0, $lastSlash);
        $filename = substr($path, $lastSlash + 1);
        if ($srcPath === '' || $filename === '') {
            return null;
        }

        $extPos = strrpos($filename, '.');
        if ($extPos === false) {
            return null;
        }
        $stem = substr($filename, 0, $extPos);
        $ext = strtolower(substr($filename, $extPos + 1));
        if (!preg_match('/^[a-z0-9]+$/', $ext)) {
            return null;
        }

        $tokens = explode('-', $stem);
        if (count($tokens) < 3) {
            return null;
        }

        $fmt = $tokens[0];
        if (!preg_match('/^[a-z0-9]+$/', $fmt)) {
            return null;
        }

        // Detect optional trailing f{8-hex} segment.
        $hash = null;
        $last = $tokens[count($tokens) - 1];
        if (preg_match('/^f([a-f0-9]{8})$/', $last, $m)) {
            $hash = $m[1];
            array_pop($tokens);
        }

        // After potential hash strip: legacy fmt-w-q (3 tokens) or crop fmt-w-h-fit-q (5+).
        if (count($tokens) === 3 && ctype_digit($tokens[1]) && ctype_digit($tokens[2])) {
            return [
                'fmt' => $fmt,
                'w' => (int) $tokens[1],
                'q' => (int) $tokens[2],
                'h' => null,
                'fit' => null,
                'hash' => $hash,
                'source' => $srcPath,
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
                'fmt' => $fmt,
                'w' => $w,
                'q' => $q,
                'h' => $h,
                'fit' => $fitToken,
                'hash' => $hash,
                'source' => $srcPath,
            ];
        }

        return null;
    }
```

The existing `isValidFitToken` and `stripFormatExtension` helpers are unaffected; `stripFormatExtension` is no longer called from `parseCachePath` (the new parser handles extensions inline) but stays in the file as a private helper for future use — or it can be removed. Remove it for cleanliness:

Find `private static function stripFormatExtension(...)` and delete the entire method.

- [ ] **Step 7: Run tests, expect pass**

```bash
vendor/bin/phpunit
```
Expected: all tests green again. ServerTest has 8 tests now (the 6 from testing-infra plan + 2 new filter-related), EndpointTest has 7 (the 6 from testing-infra plan + 1 new filter-shape).

- [ ] **Step 8: Commit**

```bash
git add lib/Glide/Server.php lib/Glide/Endpoint.php tests/Unit/Glide/ServerTest.php tests/Unit/Glide/EndpointTest.php tests/Integration/CropPipelineTest.php
git commit -m "$(cat <<'EOF'
Cache layout: asset-keyed + optional filter hash segment

Server::cachePath flips to {src}/{transformSpec}.{ext}. All variants
of one source live in one directory, easier per-asset inspection,
easier per-asset wipe.

Four transform-spec shapes:
  {fmt}-{w}-{q}                              — legacy (no crop, no filters)
  {fmt}-{w}-{h}-{fitToken}-{q}               — crop, no filters
  {fmt}-{w}-{q}-f{hash}                      — no crop, with filters
  {fmt}-{w}-{h}-{fitToken}-{q}-f{hash}       — crop, with filters

The f{hash} suffix is the first 8 chars of md5(json_encode(ksort(
$filterParams))) and gets parsed by Endpoint::parseCachePath via a
trailing-segment check. Hash determinism is enforced by ksort.

Endpoint::parseCachePath is now split-on-last-slash + parse-stem,
which handles source paths with subdirectories naturally.
stripFormatExtension is removed (the new parser handles extensions
inline).

Tests updated for the new path shapes; two new ServerTest cases
verify hash inclusion and determinism. CropPipelineTest gains a
strict regex assertion on the new layout.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: `Signature` accepts `?string $extraPayload` second arg

**Files:**
- Modify: `lib/Glide/Signature.php`
- Modify: `tests/Unit/Glide/SignatureTest.php`

- [ ] **Step 1: Add failing tests**

Append to `tests/Unit/Glide/SignatureTest.php` (inside the class, after the existing tests):

```php
    public function testSignWithExtraPayload(): void
    {
        $key = 'test-key';
        $path = 'hero.jpg/jpg-800-80-fa1b2c3d4.jpg';
        $extra = 'eyJicmkiOjEwfQ'; // pretend filter blob

        $sig = Signature::sign($path, $extra, $key);
        self::assertNotEmpty($sig);
        self::assertTrue(Signature::verify($path, $sig, $extra, $key));
    }

    public function testVerifyRejectsTamperedExtraPayload(): void
    {
        $key = 'test-key';
        $path = 'hero.jpg/jpg-800-80-fa1b2c3d4.jpg';
        $extra = 'eyJicmkiOjEwfQ';

        $sig = Signature::sign($path, $extra, $key);
        self::assertFalse(Signature::verify($path, $sig, 'eyJicmkiOjk5fQ', $key));
    }

    public function testVerifyRejectsNullExtraWhenSignedWithExtra(): void
    {
        $key = 'test-key';
        $path = 'hero.jpg/jpg-800-80-fa1b2c3d4.jpg';

        $sig = Signature::sign($path, 'eyJicmkiOjEwfQ', $key);
        self::assertFalse(Signature::verify($path, $sig, null, $key));
    }
```

- [ ] **Step 2: Run, expect failure**

```bash
vendor/bin/phpunit --filter=SignatureTest
```
Expected: `TypeError: too many arguments`. Current sign/verify only take 2 args.

- [ ] **Step 3: Update `lib/Glide/Signature.php`**

Replace the file:

```php
<?php

declare(strict_types=1);

namespace Ynamite\Media\Glide;

use Ynamite\Media\Config;

final class Signature
{
    /**
     * Sign a cache path. Optional $extraPayload is concatenated with `|` before
     * HMAC computation — used to cover the &f filter blob alongside the path.
     * The `|` delimiter cannot appear in a base64url-encoded JSON payload, so
     * the concatenation is unambiguous.
     */
    public static function sign(string $path, ?string $extraPayload = null, ?string $key = null): string
    {
        $key ??= Config::signKey();
        $payload = $extraPayload !== null && $extraPayload !== ''
            ? $path . '|' . $extraPayload
            : $path;
        return hash_hmac('sha256', $payload, $key);
    }

    public static function verify(string $path, string $signature, ?string $extraPayload = null, ?string $key = null): bool
    {
        if ($signature === '') {
            return false;
        }
        $key ??= Config::signKey();
        $payload = $extraPayload !== null && $extraPayload !== ''
            ? $path . '|' . $extraPayload
            : $path;
        return hash_equals(hash_hmac('sha256', $payload, $key), $signature);
    }
}
```

**Breaking change for callers:** the old 2-arg `verify($path, $signature)` is now `verify($path, $signature, ?$extra, ?$key)`. The `?$extra` is in position 3, `?$key` is in position 4. Existing callers that use named-args or positional 2-arg form continue to work; existing callers using 3-arg positional `verify($path, $sig, $key)` need updating. Search the codebase:

```bash
grep -rn "Signature::verify" /Users/yvestorres/Repositories/massif_img/lib /Users/yvestorres/Repositories/massif_img/tests
```

The call sites to update are:
- `lib/Glide/Endpoint.php:14` — `Signature::verify($cachePath, $signature)`. Two-arg, no change needed.
- `tests/Unit/Glide/SignatureTest.php` — already updated above to 4-arg form.
- `tests/Integration/HmacRoundtripTest.php` — currently uses 3-arg positional `Signature::verify($path, $sig, self::KEY)`. Update each call to use named arg: `Signature::verify($path, $sig, key: self::KEY)` (skipping the new `$extraPayload` defaults to null).

- [ ] **Step 4: Update `tests/Integration/HmacRoundtripTest.php`** so calls use named args for the key (avoids accidentally passing it as the new extraPayload):

Find each `Signature::verify(...)` call. Change positional `key` to named arg:

```php
// Before: Signature::verify($path, $sig, self::KEY)
// After:  Signature::verify($path, $sig, key: self::KEY)
```

And `Signature::sign(...)` similarly:

```php
// Before: Signature::sign($path, self::KEY)
// After:  Signature::sign($path, key: self::KEY)
```

- [ ] **Step 5: Run tests, expect pass**

```bash
vendor/bin/phpunit
```
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add lib/Glide/Signature.php tests/Unit/Glide/SignatureTest.php tests/Integration/HmacRoundtripTest.php
git commit -m "$(cat <<'EOF'
Signature: accept optional extraPayload for combined HMAC

sign($path, ?$extra, ?$key) and verify($path, $sig, ?$extra, ?$key)
concatenate `path|extra` (with `|` delimiter that can't appear in a
base64url-encoded JSON payload) before computing HMAC. This lets us
cover the &f filter-blob query param alongside the cache path so
filter values can't be tampered with after signing.

The 2-arg sign($path) and 2-arg verify($path, $sig) shapes remain
backward-compatible — extraPayload null falls through to
path-only signing, identical to today.

Tests in HmacRoundtripTest updated to use named args (key: ...)
for the key parameter, avoiding ambiguity with the new
extraPayload positional slot.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: `ImageBuilder` — 12 filter setter methods + bulk applier

**Files:**
- Modify: `lib/Builder/ImageBuilder.php`
- Create: `tests/Unit/Builder/ImageBuilderTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Builder;

use PHPUnit\Framework\TestCase;
use Ynamite\Media\Builder\ImageBuilder;

final class ImageBuilderTest extends TestCase
{
    private function buildAndExtractFilters(callable $configure): array
    {
        $b = new ImageBuilder('test.jpg');
        $configure($b);

        $reflection = new \ReflectionProperty($b, 'filterParams');
        return $reflection->getValue($b);
    }

    public function testBrightnessClampsRange(): void
    {
        $f = $this->buildAndExtractFilters(fn (ImageBuilder $b) => $b->brightness(200));
        self::assertSame(['bri' => 100], $f);
    }

    public function testContrastClampsRange(): void
    {
        $f = $this->buildAndExtractFilters(fn (ImageBuilder $b) => $b->contrast(-200));
        self::assertSame(['con' => -100], $f);
    }

    public function testGammaClampsAsFloat(): void
    {
        $f = $this->buildAndExtractFilters(fn (ImageBuilder $b) => $b->gamma(15.0));
        self::assertSame(['gam' => 9.99], $f);
    }

    public function testSharpenInRange(): void
    {
        $f = $this->buildAndExtractFilters(fn (ImageBuilder $b) => $b->sharpen(20));
        self::assertSame(['sharp' => 20], $f);
    }

    public function testFilterPresetPassthrough(): void
    {
        $f = $this->buildAndExtractFilters(fn (ImageBuilder $b) => $b->filter('sepia'));
        self::assertSame(['filt' => 'sepia'], $f);
    }

    public function testBgValidatesHex(): void
    {
        $valid = $this->buildAndExtractFilters(fn (ImageBuilder $b) => $b->bg('ff00cc'));
        self::assertSame(['bg' => 'ff00cc'], $valid);

        $invalid = $this->buildAndExtractFilters(fn (ImageBuilder $b) => $b->bg('xyz'));
        self::assertSame([], $invalid);
    }

    public function testBorderComposesString(): void
    {
        $f = $this->buildAndExtractFilters(fn (ImageBuilder $b) => $b->border(2, 'ff0000', 'expand'));
        self::assertSame(['border' => '2,ff0000,expand'], $f);
    }

    public function testFlipPassthrough(): void
    {
        $f = $this->buildAndExtractFilters(fn (ImageBuilder $b) => $b->flip('h'));
        self::assertSame(['flip' => 'h'], $f);
    }

    public function testWatermarkComposesAllSubParams(): void
    {
        $f = $this->buildAndExtractFilters(fn (ImageBuilder $b) => $b->watermark(
            src: 'logo.png',
            size: 0.25,
            position: 'bottom-right',
            padding: 20,
            alpha: 70,
        ));

        self::assertSame('logo.png', $f['mark']);
        self::assertSame(0.25, $f['marks']);
        self::assertSame('bottom-right', $f['markpos']);
        self::assertSame(20, $f['markpad']);
        self::assertSame(70, $f['markalpha']);
    }

    public function testFiltersBulkApplier(): void
    {
        $f = $this->buildAndExtractFilters(fn (ImageBuilder $b) => $b->filters([
            'brightness' => 10,
            'sharpen' => 20,
            'bogus' => 'value',
        ]));
        self::assertSame(['bri' => 10, 'sharp' => 20], $f);
    }

    public function testChainingMergesFilters(): void
    {
        $f = $this->buildAndExtractFilters(fn (ImageBuilder $b) =>
            $b->brightness(10)->sharpen(20)->filter('sepia')
        );
        self::assertSame(['bri' => 10, 'sharp' => 20, 'filt' => 'sepia'], $f);
    }
}
```

- [ ] **Step 2: Run, expect failure**

```bash
vendor/bin/phpunit --filter=ImageBuilderTest
```
Expected: `Error: Call to undefined method ImageBuilder::brightness()`.

- [ ] **Step 3: Add the new field, setters, and bulk applier to `lib/Builder/ImageBuilder.php`**

Add the import at the top of the file (alongside existing enum imports):

```php
use Ynamite\Media\Glide\FilterParams;
```

Add the new field in the field block (after `private ?Fit $fit = null;`):

```php
    /** @var array<string, scalar> Glide-keyed filter params. */
    private array $filterParams = [];
```

Add the bulk applier and the 12 typed setters somewhere after the existing setter cluster (a natural spot is right before `render()`):

```php
    public function brightness(int $value): self
    {
        $this->filterParams['bri'] = (int) FilterParams::clamp('bri', $value);
        return $this;
    }

    public function contrast(int $value): self
    {
        $this->filterParams['con'] = (int) FilterParams::clamp('con', $value);
        return $this;
    }

    public function gamma(float $value): self
    {
        $this->filterParams['gam'] = (float) FilterParams::clamp('gam', $value);
        return $this;
    }

    public function sharpen(int $value): self
    {
        $this->filterParams['sharp'] = (int) FilterParams::clamp('sharp', $value);
        return $this;
    }

    public function blur(int $value): self
    {
        $this->filterParams['blur'] = (int) FilterParams::clamp('blur', $value);
        return $this;
    }

    public function pixelate(int $value): self
    {
        $this->filterParams['pixel'] = (int) FilterParams::clamp('pixel', $value);
        return $this;
    }

    public function filter(string $preset): self
    {
        $this->filterParams['filt'] = $preset;
        return $this;
    }

    public function bg(string $hex): self
    {
        $validated = FilterParams::validateHex($hex);
        if ($validated !== null) {
            $this->filterParams['bg'] = $validated;
        }
        return $this;
    }

    public function border(int $width, string $color, string $method = 'overlay'): self
    {
        $this->filterParams['border'] = sprintf('%d,%s,%s', $width, $color, $method);
        return $this;
    }

    public function flip(string $axis): self
    {
        $this->filterParams['flip'] = $axis;
        return $this;
    }

    public function orient(int|string $value): self
    {
        $this->filterParams['orient'] = (string) $value;
        return $this;
    }

    public function watermark(
        string $src,
        ?float $size = null,
        ?int $width = null,
        ?int $height = null,
        string $position = 'center',
        int $padding = 0,
        int $alpha = 100,
        string $fit = 'contain',
    ): self {
        $this->filterParams['mark'] = $src;
        if ($size !== null) {
            $this->filterParams['marks'] = (float) FilterParams::clamp('marks', $size);
        }
        if ($width !== null) {
            $this->filterParams['markw'] = $width;
        }
        if ($height !== null) {
            $this->filterParams['markh'] = $height;
        }
        $this->filterParams['markpos'] = $position;
        $this->filterParams['markpad'] = max(0, $padding);
        $this->filterParams['markalpha'] = (int) FilterParams::clamp('markalpha', $alpha);
        $this->filterParams['markfit'] = $fit;
        return $this;
    }

    /**
     * Bulk-apply filters from a friendly-keyed array. Translates / clamps /
     * drops via FilterParams::normalize. Subsequent setter calls override.
     *
     * @param array<string, scalar> $filters
     */
    public function filters(array $filters): self
    {
        $normalized = FilterParams::normalize($filters);
        $this->filterParams = array_merge($this->filterParams, $normalized);
        return $this;
    }
```

Update the `render()` method to thread `$this->filterParams` to `PictureRenderer::render`. Find the existing `PictureRenderer::render(...)` call and add `$this->filterParams` as a new positional argument **at the end** (after `$this->fit`):

```php
        return (new PictureRenderer(
            new SrcsetBuilder(),
            new UrlBuilder(),
            new Placeholder(),
        ))->render(
            $image,
            $this->width,
            $this->height,
            $this->ratio,
            $this->alt,
            $this->sizes,
            $this->widthsOverride,
            $this->formatsOverride,
            $this->qualityOverride,
            $this->loading,
            $this->decoding,
            $this->fetchPriority,
            $this->withBlurhashAttr,
            $this->class,
            $this->fit,
            $this->filterParams,
        );
```

Also update the `Preloader::queue(...)` call inside `render()` to include filter params (find the existing call):

```php
        if ($this->preload) {
            Preloader::queue(
                $image,
                $this->width,
                $this->height,
                $this->ratio,
                $this->sizes,
                $this->widthsOverride,
                $this->formatsOverride,
                $this->qualityOverride,
                $this->fit,
                $this->filterParams,
            );
        }
```

(`Preloader::queue` will gain matching parameters in Task 6.)

- [ ] **Step 4: Run tests, expect failures only outside ImageBuilderTest**

```bash
vendor/bin/phpunit
```
Expected: ImageBuilderTest passes; PictureRenderer + Preloader signature mismatches surface as errors elsewhere. We'll fix those next.

- [ ] **Step 5: Update `PictureRenderer::render` signature**

In `lib/View/PictureRenderer.php`, append `array $filterParams = []` to the `render(...)` parameter list as the last parameter. The body changes come in Task 5; for now, just make the signature accept it without using it:

```php
    public function render(
        ResolvedImage $image,
        ?int $width = null,
        ?int $height = null,
        ?float $ratio = null,
        ?string $alt = null,
        ?string $sizes = null,
        ?array $widthsOverride = null,
        ?array $formats = null,
        ?array $qualityOverride = null,
        Loading $loading = Loading::LAZY,
        Decoding $decoding = Decoding::ASYNC,
        FetchPriority $fetchPriority = FetchPriority::AUTO,
        bool $withBlurhashAttr = false,
        ?string $class = null,
        ?Fit $fit = null,
        array $filterParams = [],
    ): string {
```

- [ ] **Step 6: Update `Preloader::queue` signature**

In `lib/Pipeline/Preloader.php`, find the `queue(...)` static method and append `array $filterParams = []` as the last param. The body changes come in Task 6.

- [ ] **Step 7: Run tests**

```bash
vendor/bin/phpunit
```
Expected: all green. ImageBuilderTest passes (11 tests). Other test files unaffected.

- [ ] **Step 8: Commit**

```bash
git add lib/Builder/ImageBuilder.php lib/View/PictureRenderer.php lib/Pipeline/Preloader.php tests/Unit/Builder/ImageBuilderTest.php
git commit -m "$(cat <<'EOF'
ImageBuilder: 12 filter setter methods + bulk filters() applier

Each typed setter (brightness, contrast, gamma, sharpen, blur,
pixelate, filter, bg, border, flip, orient, watermark) writes to
$this->filterParams keyed by Glide's short name. Numerics clamp via
FilterParams::clamp; bg validates as 6-hex-char (invalid → drop);
border composes its multi-arg string; watermark is a single
composite method covering 8 sub-params.

filters([...]) is a bulk applier that goes through
FilterParams::normalize for friendly-name translation and
validation.

PictureRenderer::render and Preloader::queue gain trailing
`array $filterParams = []` parameters to receive the values from
the builder. Bodies still ignore them — wiring lands in Tasks 5–6.

11 unit tests cover clamping, hex validation, multi-arg
composition, watermark, bulk applier, and chaining.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: `PictureRenderer` — thread `$filterParams` to `UrlBuilder`

**Files:**
- Modify: `lib/View/PictureRenderer.php`

- [ ] **Step 1: Read the current `buildSrcset` and `urlBuilder->build` call sites**

```bash
grep -n "urlBuilder->build" /Users/yvestorres/Repositories/massif_img/lib/View/PictureRenderer.php
```

There are two call sites:
- Inside `buildSrcset` (multiple URL emissions for srcset).
- For the `$fallbackSrc` (the `<img src=>` fallback URL).

- [ ] **Step 2: Update the `buildSrcset` helper signature**

In `lib/View/PictureRenderer.php`, find `private function buildSrcset(...)` and add `array $filterParams` as the last param:

```php
    private function buildSrcset(
        ResolvedImage $image,
        array $widths,
        string $format,
        ?int $quality,
        ?float $effectiveRatio,
        ?string $fitToken,
        array $filterParams,
    ): string {
        $entries = [];
        foreach ($widths as $w) {
            $h = ($effectiveRatio !== null && $fitToken !== null) ? (int) round($w / $effectiveRatio) : null;
            $url = $this->urlBuilder->build($image, $w, $format, $quality, $h, $fitToken, $filterParams);
            $entries[] = $url . ' ' . $w . 'w';
        }
        return implode(', ', $entries);
    }
```

- [ ] **Step 3: Update both call sites of `buildSrcset` and the `urlBuilder->build` for fallback**

Inside `render()`:

```php
            $srcset = $this->buildSrcset($image, $widths, $fmt, $quality, $effectiveRatio, $fitToken, $filterParams);
```

```php
        $fallbackSrcset = $this->buildSrcset($image, $widths, $fallbackFormat, $fallbackQuality, $effectiveRatio, $fitToken, $filterParams);
```

```php
        $fallbackSrc = $this->urlBuilder->build($image, $midWidth, $fallbackFormat, $fallbackQuality, $midHeight, $fitToken, $filterParams);
```

- [ ] **Step 4: Run tests, expect signature mismatch errors in UrlBuilder calls**

```bash
vendor/bin/phpunit
```
Expected: failures in tests touching PictureRenderer because UrlBuilder::build doesn't accept the new arg yet. Wiring lands in Task 6.

- [ ] **Step 5: Commit (compilation-broken; OK because next task fixes it)**

Skip the commit on this task standalone — bundle with Task 6 below since they're tightly coupled and shouldn't land separately.

---

### Task 6: `UrlBuilder` — accept `$filterParams`, emit `&f`, sign `path|f`

**Files:**
- Modify: `lib/Pipeline/UrlBuilder.php`
- Create: (extends) `tests/Unit/Pipeline/UrlBuilderTest.php` (optional — coverage via integration is sufficient)

- [ ] **Step 1: Update `lib/Pipeline/UrlBuilder.php`**

Replace the file:

```php
<?php

declare(strict_types=1);

namespace Ynamite\Media\Pipeline;

use rex_url;
use Ynamite\Media\Config;
use Ynamite\Media\Glide\Server;
use Ynamite\Media\Glide\Signature;

final class UrlBuilder
{
    /**
     * Build a URL for a single variant of a resolved image.
     *
     * Glide params (w/h/q/fm/fit) are encoded into the cache path itself.
     * Filter params ride in a separate `&f=base64url(json)` query parameter
     * because their values can include special characters and the cache key
     * only carries an 8-char hash for unambiguity. HMAC covers `path|f`
     * together when filters are present.
     *
     * @param array<string, scalar> $filterParams Glide-keyed filter params.
     */
    public function build(
        ResolvedImage $image,
        int $width,
        string $format,
        ?int $quality = null,
        ?int $height = null,
        ?string $fitToken = null,
        array $filterParams = [],
    ): string {
        $quality ??= Config::quality($format);

        if (Config::cdnEnabled()) {
            return $this->buildCdnUrl($image, $width, $format, $quality, $height, $fitToken, $filterParams);
        }

        $cachePath = Server::cachePath($image->sourcePath, [
            'fm' => $format,
            'w' => $width,
            'q' => $quality,
            'h' => $height,
            'fit' => $fitToken,
            'filters' => $filterParams,
        ]);

        $filterBlob = '';
        if ($filterParams !== []) {
            ksort($filterParams);
            $filterBlob = self::base64UrlEncode(json_encode($filterParams, JSON_FORCE_OBJECT));
        }

        $signature = Signature::sign($cachePath, $filterBlob !== '' ? $filterBlob : null);

        $url = rex_url::addonAssets(Config::ADDON, 'cache/' . $cachePath);
        $url .= '?s=' . $signature;
        if ($image->mtime > 0) {
            $url .= '&v=' . $image->mtime;
        }
        if ($filterBlob !== '') {
            $url .= '&f=' . $filterBlob;
        }
        return $url;
    }

    private function buildCdnUrl(
        ResolvedImage $image,
        int $width,
        string $format,
        int $quality,
        ?int $height,
        ?string $fitToken,
        array $filterParams,
    ): string {
        $template = Config::cdnUrlTemplate();
        if ($template === '') {
            $template = '{src}?w={w}&q={q}&fm={fm}';
        }

        $filterBlob = '';
        if ($filterParams !== []) {
            ksort($filterParams);
            $filterBlob = self::base64UrlEncode(json_encode($filterParams, JSON_FORCE_OBJECT));
        }

        $expanded = strtr($template, [
            '{w}' => (string) $width,
            '{q}' => (string) $quality,
            '{fm}' => $format,
            '{src}' => $image->sourcePath,
            '{h}' => $height !== null ? (string) $height : '',
            '{fit}' => $fitToken ?? '',
            '{f}' => $filterBlob,
        ]);

        $base = Config::cdnBase();
        return $base . '/' . ltrim($expanded, '/');
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string|false
    {
        return base64_decode(strtr($data, '-_', '+/'), true);
    }
}
```

- [ ] **Step 2: Update `lib/Pipeline/Preloader.php` — accept `$filterParams` and pass through**

Find the `queue(...)` static method. Update its signature to include `array $filterParams = []` as the last param. Inside the queue entry array push, add `'filterParams' => $filterParams`. Find the loop in `drain()` that builds preload URLs; pass `$filterParams` to `$urlBuilder->build`:

```php
    public static function queue(
        ResolvedImage $image,
        ?int $width = null,
        ?int $height = null,
        ?float $ratio = null,
        ?string $sizes = null,
        ?array $widthsOverride = null,
        ?array $formatsOverride = null,
        ?array $qualityOverride = null,
        ?Fit $fit = null,
        array $filterParams = [],
    ): void {
        self::$queue[] = [
            'image' => $image,
            'width' => $width,
            'height' => $height,
            'ratio' => $ratio,
            'sizes' => $sizes,
            'widths' => $widthsOverride,
            'formats' => $formatsOverride,
            'quality' => $qualityOverride,
            'fit' => $fit,
            'filterParams' => $filterParams,
        ];
    }
```

(Adapt to whatever the existing field set is; this is the new shape after the cropping plan landed `$fit`.)

In the `drain()` body, where preload URLs are built, change the `$urlBuilder->build(...)` call to pass `$entry['filterParams'] ?? []` as the last arg.

(If `Preloader::drain()` doesn't currently pass `$fitToken` to UrlBuilder either, that's an existing gap from the cropping work — fix it in the same commit by computing `$fitToken` from `$entry['fit']` and `$entry['ratio']` similarly to how PictureRenderer does it. See `PictureRenderer::buildFitToken` for reference.)

- [ ] **Step 3: Run tests**

```bash
vendor/bin/phpunit
```
Expected: all green. The CropPipelineTest's `testCachePathContainsCoverFitToken` continues to pass (filters are empty, behavior is bit-identical to before this task).

- [ ] **Step 4: Commit (bundles Task 5 + Task 6)**

```bash
git add lib/View/PictureRenderer.php lib/Pipeline/UrlBuilder.php lib/Pipeline/Preloader.php
git commit -m "$(cat <<'EOF'
PictureRenderer + UrlBuilder + Preloader: thread filterParams through

Filter params flow from ImageBuilder → PictureRenderer →
UrlBuilder. UrlBuilder computes ksort-sorted base64url-JSON blob,
embeds it in &f= query param, signs `path|f` together via
Signature::sign($path, $extraPayload). When filters are empty, the
URL is bit-identical to before this commit (no &f, signature
covers $path alone).

CDN URL template gains a {f} token (urlencoded blob, empty when no
filters) — existing templates without it produce identical URLs.

Preloader::queue gains the `array $filterParams = []` parameter so
preload <link> emissions hit the same filtered cache as the
rendered <img>. drain() passes the stored filterParams to
UrlBuilder.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: `Endpoint::handle` — decode `&f`, recompute hash, set active filters

**Files:**
- Modify: `lib/Glide/Endpoint.php`
- Modify: `lib/Glide/Server.php`

These two MUST land together: Endpoint sets `Server::$activeFilterParams`, the Server's `cachePathCallable` reads from it. Splitting would leave the cache path callable using stale or empty filter params.

- [ ] **Step 1: Update `lib/Glide/Server.php` to add `$activeFilterParams` static + setter, and read it in the closure**

In `lib/Glide/Server.php`, add a static property + setter:

```php
    /** @var array<string, scalar> Active filter params for the current request. */
    private static array $activeFilterParams = [];

    public static function setActiveFilters(array $params): void
    {
        self::$activeFilterParams = $params;
    }

    public static function clearActiveFilters(): void
    {
        self::$activeFilterParams = [];
    }
```

Update `cachePathCallable()` to thread the static into the closure's params payload:

```php
    public static function cachePathCallable(): Closure
    {
        // See class-level docblock for the closure-bind gotchas. The closure
        // additionally reads Server::$activeFilterParams so on-disk paths match
        // the URL emission's filter hash even when Glide internally calls
        // Server::cachePath without filter context.
        return fn (string $path, array $params): string => Server::cachePath($path, [
            ...$params,
            'filters' => self::$activeFilterParams,
        ]);
    }
```

- [ ] **Step 2: Update `lib/Glide/Endpoint.php::handle()`**

Replace the existing `handle()` method:

```php
    public static function handle(): void
    {
        $cachePath = (string) ($_GET['p'] ?? '');
        $signature = (string) ($_GET['s'] ?? '');
        $filterBlob = (string) ($_GET['f'] ?? '');

        $extraPayload = $filterBlob !== '' ? $filterBlob : null;

        if ($cachePath === '' || !Signature::verify($cachePath, $signature, $extraPayload)) {
            self::respond(403, 'Forbidden');
            return;
        }

        $parsed = self::parseCachePath($cachePath);
        if ($parsed === null) {
            self::respond(400, 'Bad request');
            return;
        }

        $filterParams = [];
        if ($parsed['hash'] !== null) {
            if ($filterBlob === '') {
                self::respond(400, 'Bad request');
                return;
            }
            $decoded = json_decode((string) UrlBuilder::base64UrlDecode($filterBlob), true);
            if (!is_array($decoded)) {
                self::respond(400, 'Bad request');
                return;
            }
            $filterParams = $decoded;
            ksort($filterParams);
            $expectedHash = substr(md5(json_encode($filterParams, JSON_FORCE_OBJECT)), 0, 8);
            if (!hash_equals($expectedHash, $parsed['hash'])) {
                self::respond(400, 'Bad request');
                return;
            }
        }

        try {
            $params = [
                'w' => $parsed['w'],
                'q' => $parsed['q'],
                'fm' => $parsed['fmt'],
            ];
            if ($parsed['h'] !== null) {
                $params['h'] = $parsed['h'];
            }
            if ($parsed['fit'] !== null) {
                $params['fit'] = str_starts_with($parsed['fit'], 'cover-')
                    ? 'crop-' . substr($parsed['fit'], strlen('cover-'))
                    : $parsed['fit'];
            }
            // Merge filter params last so they can't override w/q/fm/h/fit accidentally.
            $params = array_merge($filterParams, $params);

            Server::setActiveFilters($filterParams);
            try {
                $server = Server::create();
                $relCachePath = $server->makeImage($parsed['source'], $params);
                $bytes = $server->getCache()->read($relCachePath);
            } finally {
                Server::clearActiveFilters();
            }
        } catch (Throwable $e) {
            rex_logger::logException($e);
            self::respond(404, 'Not found');
            return;
        }

        $mime = self::mimeFor($parsed['fmt']);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: public, max-age=31536000, immutable');
        header('ETag: "' . md5($bytes) . '"');
        echo $bytes;
    }
```

Add a `use Ynamite\Media\Pipeline\UrlBuilder;` import at the top of `Endpoint.php` if not already present (for the static `base64UrlDecode` helper).

- [ ] **Step 3: Run tests**

```bash
vendor/bin/phpunit
```
Expected: all green. No new tests needed at this layer — Endpoint::handle is integration-bound and gets exercised via FilterPipelineTest in Task 9.

- [ ] **Step 4: Commit**

```bash
git add lib/Glide/Endpoint.php lib/Glide/Server.php
git commit -m "$(cat <<'EOF'
Endpoint + Server: filter blob decode, hash validation, active state

Endpoint::handle now:
  1. Verifies HMAC over `path|filterBlob` together (defense in depth
     even though parseCachePath also re-validates the hash).
  2. Decodes &f base64url-JSON; rejects 400 if missing-when-hash-
     present, malformed, or hash-mismatch.
  3. Sets Server::$activeFilterParams before calling makeImage so
     Glide's internal Server::cachePath invocations produce paths
     consistent with the URL we emitted. clearActiveFilters in a
     finally block.

Server::cachePathCallable now spreads the static \$activeFilterParams
into the cache-path computation. Test seam: setActiveFilters() /
clearActiveFilters() are public static; per-test setUp can reset.

Static state is acceptable here because handle() is the only caller,
exits after one request, and clearActiveFilters runs in a finally.
Documented as a future-contributor-warning gotcha.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 8: `Image::picture()` — accept `array $filters = []` arg

**Files:**
- Modify: `lib/Image.php`

- [ ] **Step 1: Add the `$filters` parameter and forward**

In `lib/Image.php`, append `array $filters = []` to the `picture(...)` method signature (last positional, after `$fit`):

```php
    public static function picture(
        string|rex_media $src,
        ?string $alt = null,
        ?int $width = null,
        ?int $height = null,
        ?float $ratio = null,
        ?string $sizes = null,
        Loading|string $loading = Loading::LAZY,
        Decoding|string $decoding = Decoding::ASYNC,
        FetchPriority|string $fetchPriority = FetchPriority::AUTO,
        ?string $focal = null,
        bool $preload = false,
        ?string $class = null,
        Fit|string|null $fit = null,
        array $filters = [],
    ): string {
```

Inside the body, after the `$fit` handling block, add:

```php
        if ($filters !== []) {
            $b->filters($filters);
        }
```

- [ ] **Step 2: Lint**

```bash
php -l /Users/yvestorres/Repositories/massif_img/lib/Image.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 3: Run tests**

```bash
vendor/bin/phpunit
```
Expected: all green (additive arg, existing tests unaffected).

- [ ] **Step 4: Commit**

```bash
git add lib/Image.php
git commit -m "$(cat <<'EOF'
Image::picture: accept array \$filters = []

14th positional arg, defaulted to []. Forwarded to
ImageBuilder::filters() which goes through FilterParams::normalize
for friendly-name translation, clamping, and invalid-key dropping.

Pic::picture inherits via extends. Co-exists with the typed builder
methods (->brightness, ->sharpen, etc.) — callers can use either
style.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 9: `RexPic` — pass 19 new filter attributes through

**Files:**
- Modify: `lib/Var/RexPic.php`
- Modify: `tests/Unit/Var/RexPicTest.php`

- [ ] **Step 1: Add failing tests**

Append to `tests/Unit/Var/RexPicTest.php`:

```php
    public function testGetOutputEmitsFiltersArrayForBrightness(): void
    {
        $code = $this->buildOutput(['src' => 'hero.jpg', 'brightness' => '10']);

        self::assertIsString($code);
        self::assertStringContainsString("filters: ['brightness' => 10]", $code);
    }

    public function testGetOutputEmitsFiltersArrayForMultipleAttributes(): void
    {
        $code = $this->buildOutput([
            'src' => 'hero.jpg',
            'brightness' => '10',
            'sharpen' => '20',
            'filter' => 'sepia',
        ]);

        self::assertIsString($code);
        self::assertStringContainsString('filters: [', $code);
        self::assertStringContainsString("'brightness' => 10", $code);
        self::assertStringContainsString("'sharpen' => 20", $code);
        self::assertStringContainsString("'filter' => 'sepia'", $code);
    }

    public function testGetOutputOmitsFiltersWhenNonePresent(): void
    {
        $code = $this->buildOutput(['src' => 'hero.jpg']);

        self::assertIsString($code);
        self::assertStringNotContainsString('filters:', $code);
    }

    public function testGetOutputPassesWatermarkAttributes(): void
    {
        $code = $this->buildOutput([
            'src' => 'hero.jpg',
            'mark' => 'logo.png',
            'markpos' => 'bottom-right',
            'markalpha' => '70',
        ]);

        self::assertIsString($code);
        self::assertStringContainsString("'mark' => 'logo.png'", $code);
        self::assertStringContainsString("'markpos' => 'bottom-right'", $code);
        self::assertStringContainsString("'markalpha' => 70", $code);
    }
```

- [ ] **Step 2: Run, expect failure**

```bash
vendor/bin/phpunit --filter=RexPicTest
```
Expected: the new tests fail because RexPic doesn't yet emit a `filters: [...]` block.

- [ ] **Step 3: Update `lib/Var/RexPic.php`**

Find the existing `getOutput()` method. After the existing per-key foreach (where each known attribute gets pushed onto `$args`), add a separate filter-aggregation block:

```php
        // Filter attributes — collect into a single $filters array passed to
        // Image::picture. FilterParams::normalize translates / clamps server-side.
        $filterAttrs = [
            'brightness', 'contrast', 'gamma', 'sharpen', 'blur', 'pixelate',
            'filter', 'bg', 'border', 'flip', 'orient',
            'mark', 'marks', 'markw', 'markh', 'markpos', 'markpad', 'markalpha', 'markfit',
        ];
        $filterPairs = [];
        foreach ($filterAttrs as $key) {
            $val = $this->getParsedArg($key);
            if ($val !== null) {
                $filterPairs[] = "'" . $key . "' => " . $val;
            }
        }
        if ($filterPairs !== []) {
            $args[] = 'filters: [' . implode(', ', $filterPairs) . ']';
        }
```

Place this block immediately before the final `return '\\Ynamite\\Media\\Image::picture(' . implode(', ', $args) . ')';` line.

- [ ] **Step 4: Run tests, expect pass**

```bash
vendor/bin/phpunit --filter=RexPicTest
```
Expected: all green (the original 7 + new 4 = 11).

- [ ] **Step 5: Commit**

```bash
git add lib/Var/RexPic.php tests/Unit/Var/RexPicTest.php
git commit -m "$(cat <<'EOF'
RexPic: pass 19 filter attributes through to Image::picture

REX_PIC[src=\"...\" brightness=\"10\" sharpen=\"20\" filter=\"sepia\"]
now emits the corresponding `filters: ['brightness' => 10, 'sharpen'
=> 20, 'filter' => 'sepia']` arg in the Image::picture call. Same
for the 8 watermark sub-params (mark, marks, markw, markh, markpos,
markpad, markalpha, markfit).

FilterParams::normalize handles translation and clamping
server-side; RexPic just collects whatever attributes the editor
provided.

Four new unit tests cover single-filter, multi-filter, none-present,
and watermark composition.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 10: `assets/cache/.gitignore`

**Files:**
- Create: `assets/cache/.gitignore`

- [ ] **Step 1: Create the file**

```bash
cd /Users/yvestorres/Repositories/massif_img
mkdir -p assets/cache
```

Create `assets/cache/.gitignore` with content:

```
*
!.gitignore
```

- [ ] **Step 2: Commit**

```bash
git add assets/cache/.gitignore
git commit -m "$(cat <<'EOF'
assets/cache: gitignore contents, keep the directory

Cache files generated at runtime — none of them belong in git.
The directory itself stays tracked (so it exists on a fresh clone)
via `*\\n!.gitignore`.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 11: Integration test — `FilterPipelineTest`

**Files:**
- Create: `tests/Integration/FilterPipelineTest.php`

- [ ] **Step 1: Create the test**

```php
<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Integration;

use PHPUnit\Framework\TestCase;
use Ynamite\Media\Glide\Server;
use Ynamite\Media\Glide\Signature;

final class FilterPipelineTest extends TestCase
{
    private string $tmpDir;
    private string $sourceDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        \rex_config::_reset();
        \rex_config::set('massif_media', 'sign_key', 'integration-test-key');

        $this->tmpDir = sys_get_temp_dir() . '/massif_media_filters_' . uniqid('', true);
        $this->sourceDir = $this->tmpDir . '/source';
        $this->cacheDir = $this->tmpDir . '/cache';
        @mkdir($this->sourceDir, 0777, true);
        @mkdir($this->cacheDir, 0777, true);

        copy(
            __DIR__ . '/../_fixtures/landscape-800x600.jpg',
            $this->sourceDir . '/hero.jpg',
        );
    }

    protected function tearDown(): void
    {
        Server::clearActiveFilters();
        \rex_dir::delete($this->tmpDir, true);
    }

    public function testFilterParamsContributeToCachePathHash(): void
    {
        $unfilteredPath = Server::cachePath('hero.jpg', ['fm' => 'jpg', 'w' => 400, 'q' => 80]);
        $filteredPath = Server::cachePath('hero.jpg', [
            'fm' => 'jpg', 'w' => 400, 'q' => 80,
            'filters' => ['bri' => 10],
        ]);

        self::assertNotSame($unfilteredPath, $filteredPath);
        self::assertMatchesRegularExpression('@-f[a-f0-9]{8}\.jpg$@', $filteredPath);
    }

    public function testGreyscaleFilterProducesGreyscaleVariant(): void
    {
        Server::setActiveFilters(['filt' => 'greyscale']);
        try {
            $server = Server::create($this->sourceDir, $this->cacheDir);
            $rel = $server->makeImage('hero.jpg', [
                'fm' => 'jpg', 'w' => 200, 'q' => 80,
                'filt' => 'greyscale',
            ]);
        } finally {
            Server::clearActiveFilters();
        }

        $cacheFile = $this->cacheDir . '/' . $rel;
        self::assertFileExists($cacheFile);
        self::assertMatchesRegularExpression('@hero\.jpg/jpg-200-80-f[a-f0-9]{8}\.jpg$@', $rel);

        // Greyscale → R/G/B channels collapse to (near-)equal values per pixel.
        $im = imagecreatefromjpeg($cacheFile);
        $w = imagesx($im);
        $h = imagesy($im);
        $sampleCount = 0;
        $greyMatch = 0;
        for ($x = 0; $x < $w; $x += 20) {
            for ($y = 0; $y < $h; $y += 20) {
                $rgb = imagecolorat($im, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $sampleCount++;
                // Greyscale: max channel - min channel within tolerance.
                if (max($r, $g, $b) - min($r, $g, $b) <= 5) {
                    $greyMatch++;
                }
            }
        }
        imagedestroy($im);
        self::assertGreaterThan(0, $sampleCount);
        $matchRate = $greyMatch / $sampleCount;
        self::assertGreaterThan(0.9, $matchRate, "Expected >90% of sampled pixels to be ~grey; got " . round($matchRate * 100) . '%');
    }

    public function testTamperedFilterBlobFailsHmacVerification(): void
    {
        $key = 'integration-test-key';
        $cachePath = Server::cachePath('hero.jpg', [
            'fm' => 'jpg', 'w' => 200, 'q' => 80,
            'filters' => ['bri' => 10],
        ]);
        $filterBlob = self::base64UrlEncode(json_encode(['bri' => 10], JSON_FORCE_OBJECT));
        $sig = Signature::sign($cachePath, $filterBlob, $key);

        self::assertTrue(Signature::verify($cachePath, $sig, $filterBlob, $key));

        $tamperedBlob = self::base64UrlEncode(json_encode(['bri' => 99], JSON_FORCE_OBJECT));
        self::assertFalse(Signature::verify($cachePath, $sig, $tamperedBlob, $key));
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
```

- [ ] **Step 2: Run the test**

```bash
vendor/bin/phpunit --testsuite=integration --filter=FilterPipelineTest
```
Expected: `OK (3 tests, ~6 assertions)`. The greyscale test takes ~1-2s for the Glide pipeline.

- [ ] **Step 3: Commit**

```bash
git add tests/Integration/FilterPipelineTest.php
git commit -m "$(cat <<'EOF'
test: filter pipeline integration coverage

Three tests exercise the filter end-to-end:

- testFilterParamsContributeToCachePathHash: same source + same w/q
  but different filter values produce different cache paths, with
  the f{hash} suffix matching [a-f0-9]{8}.

- testGreyscaleFilterProducesGreyscaleVariant: applies filt=greyscale
  via Server::makeImage (with active filters set), reads the
  generated cache file, samples pixels at a 20-pixel grid and
  asserts >90% are within tolerance of grey (R/G/B max-min ≤ 5).

- testTamperedFilterBlobFailsHmacVerification: signs with one filter
  blob, verifies that an alternate blob with the same path fails.

setUp seeds a deterministic sign key in rex_config; tearDown calls
Server::clearActiveFilters and removes the temp dir.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 12: README — Filter section, Watermark subsection, URL-Schema update

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Add a "Filter" subsection inside "REX_PIC — Placeholder für Inhaltspflege" section, alongside the existing "Cropping" subsection**

Insert after the existing `### Cropping (\`fit\`)` subsection:

```markdown
### Filter

Glide-basierte Image-Filter sind als REX_PIC-Attribute verfügbar — sowohl klassische Color-Tweaks (Brightness, Contrast, Gamma) als auch Sharpen / Blur, Color-Presets (`greyscale`, `sepia`), Background-Color, Border, Flip / Orient sowie Watermark.

| Attribut | Wert | Beschreibung |
|---|---|---|
| `brightness` | -100..100 | Helligkeit; 0 = unverändert. |
| `contrast` | -100..100 | Kontrast. |
| `gamma` | 0.1..9.99 | Gamma-Korrektur. |
| `sharpen` | 0..100 | Schärfung. |
| `blur` | 0..100 | Weichzeichner. |
| `pixelate` | 0..1000 | Pixelblock-Größe. |
| `filter` | `greyscale` \| `sepia` | Color-Preset. |
| `bg` | 6 Hex (z. B. `ffffff`) | Hintergrundfarbe — relevant z. B. wenn `fit="contain"` Lücken erzeugt. |
| `border` | `width,color,method` | Rahmen, z. B. `border="2,000000,expand"`. `method` = `overlay` / `shadow` / `expand`. |
| `flip` | `h` \| `v` \| `both` | Spiegelung. |
| `orient` | `auto` \| `0` \| `90` \| `180` \| `270` | Rotation. |

Numerische Werte ausserhalb des Ranges werden automatisch geclampt (z. B. `brightness="200"` → `100`). Ungültige Hex-Werte werden ignoriert. Unbekannte String-Werte (`filter="nonsense"`) werden an Glide durchgereicht; Glide ignoriert nicht-erkannte Filter still.

**Beispiele:**

```
REX_PIC[src="hero.jpg" width="800" filter="sepia"]
REX_PIC[src="hero.jpg" width="800" brightness="10" sharpen="20"]
REX_PIC[src="hero.jpg" width="800" ratio="1:1" fit="contain" bg="ffffff"]
REX_PIC[src="hero.jpg" width="800" flip="h" orient="90"]
```

### Watermark

Wasserzeichen via 8 separate Attribute:

| Attribut | Wert | Beschreibung |
|---|---|---|
| `mark` | string (Pflicht für Watermark) | Pfad im REDAXO-Mediapool. |
| `marks` | 0.0..1.0 | Relative Größe (0.25 = 25 % der Bildbreite). |
| `markw` | int | Pixel-Breite (overrides `marks`). |
| `markh` | int | Pixel-Höhe (overrides `marks`). |
| `markpos` | siehe Glide | `top-left`, `top`, `top-right`, `left`, `center`, `right`, `bottom-left`, `bottom`, `bottom-right`. |
| `markpad` | int | Abstand zum Rand in px. |
| `markalpha` | 0..100 | Deckkraft. |
| `markfit` | siehe Glide | Wie das Watermark in seine Box eingepasst wird (`contain` / `max` / `fill` / `stretch` / `crop`). |

```
REX_PIC[src="hero.jpg" width="1200" mark="logos/brand.png" marks="0.2" markpos="bottom-right" markpad="20" markalpha="70"]
```

PHP-API:

```php
echo Image::for('hero.jpg')
    ->width(1200)
    ->watermark('logos/brand.png', size: 0.2, position: 'bottom-right', padding: 20, alpha: 70)
    ->render();
```

Existiert die Watermark-Datei nicht im Mediapool, wird der betroffene Variant-Request mit 404 beantwortet — der `<picture>` Tag enthält dann u. U. broken-image-Glyphs für diese Variante. Das Watermark-Attribut idealerweise an Templates / Module heften, wo der Pfad kontrolliert ist.
```

- [ ] **Step 2: Update the URL-Schema section**

Find the URL-Schema section. Replace its examples block to reflect the asset-keyed layout:

```markdown
Generierte Varianten werden hier abgelegt — vier Cache-Pfad-Formen:

```
assets/addons/massif_media/cache/{src}/{fmt}-{w}-{q}.{ext}                              (kein Crop, keine Filter)
assets/addons/massif_media/cache/{src}/{fmt}-{w}-{h}-{fitToken}-{q}.{ext}               (mit Crop)
assets/addons/massif_media/cache/{src}/{fmt}-{w}-{q}-f{hash}.{ext}                      (mit Filtern)
assets/addons/massif_media/cache/{src}/{fmt}-{w}-{h}-{fitToken}-{q}-f{hash}.{ext}       (Crop + Filter)

z. B.  assets/addons/massif_media/cache/hero.jpg/avif-1080-50.avif
       assets/addons/massif_media/cache/hero.jpg/avif-800-800-cover-50-50-50.avif
       assets/addons/massif_media/cache/hero.jpg/jpg-800-80-fA1B2C3D4.jpg
       assets/addons/massif_media/cache/gallery/2024/atelier.jpg/avif-1920-1920-cover-30-70-50.avif
```

`fitToken` ist eines von: `cover-{focalX}-{focalY}` / `contain` / `stretch`. `f{hash}` enthält die ersten 8 Hex-Chars von `md5(json_encode(ksort(filterParams)))`. Der vollständige Filter-Blob reist als `&f=base64url(json)` im URL-Query mit; HMAC deckt `path|f` zusammen ab.
```

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "$(cat <<'EOF'
README: Filter + Watermark sections, asset-keyed URL-Schema

- New "### Filter" subsection under REX_PIC with a 11-row table
  covering the simple-filter attributes (brightness, contrast,
  gamma, sharpen, blur, pixelate, filter, bg, border, flip, orient),
  ranges, clamping behavior, and four examples.

- New "### Watermark" subsection with the 8-attribute table for
  mark/marks/markw/markh/markpos/markpad/markalpha/markfit, plus
  a REX_PIC and a PHP-API example.

- URL-Schema section now lists all four cache-path shapes
  (no-crop, crop, filters, crop+filters), the asset-keyed structure
  ({src}/{transform}.{ext}), and notes that filters are signed
  alongside the path via the &f query param.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 13: CHANGELOG — Added/Changed entries for filters + asset-keyed cache

**Files:**
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Prepend entries under `## [Unreleased]`**

Under `### Added`, prepend (above existing entries):

```markdown
- **Glide filter passthrough** — kompletter Manipulator-Surface über `Image::picture(filters: [...])`, `Image::for(...)->brightness/contrast/gamma/sharpen/blur/pixelate/filter/bg/border/flip/orient/watermark(...)` und 19 neue REX_PIC-Attribute (`brightness`, `contrast`, `gamma`, `sharpen`, `blur`, `pixelate`, `filter`, `bg`, `border`, `flip`, `orient`, `mark`, `marks`, `markw`, `markh`, `markpos`, `markpad`, `markalpha`, `markfit`). Numerische Ranges werden automatisch geclampt; ungültige Hex-Werte werden gedroppt; unbekannte Enum-Werte gehen an Glide durch (Glide ignoriert nicht-erkannte Filter still). Single-source Validation lebt in der neuen `lib/Glide/FilterParams.php`.
- **`assets/cache/.gitignore`** — Cache-Dateien werden nie committet. Verzeichnis bleibt im Repo (`*\n!.gitignore`).
```

Under `### Changed`, prepend:

```markdown
- **Cache-Layout flippt zu asset-keyed**: `cache/{src}/{transform}.{ext}` statt `cache/{transform}/{src}.{ext}`. Alle Varianten einer Quelle leben in einem Verzeichnis — bessere Inspektion, einfacheres Per-Asset-Wipen. Source-Subdirectories (`gallery/2024/foo.jpg`) bleiben erhalten. **Bestehende Cache-Dateien aus alten Layout sind orphaned** — `Cache leeren` einmalig nach dem Update räumt sie auf.
- **Cache-Key erweitert um optionalen `f{hash}`-Suffix bei Filter-Anfragen**: `{fmt}-{w}-{q}-f{hash}.{ext}` (bzw. `{fmt}-{w}-{h}-{fitToken}-{q}-f{hash}.{ext}` mit Crop). `{hash}` = `substr(md5(json_encode(ksort(filterParams))), 0, 8)`. Hash ist deterministisch unabhängig von Filter-Reihenfolge (durch `ksort`).
- **URL gewinnt `&f=base64url(json)`-Query-Param** bei Filter-Anfragen. HMAC deckt jetzt `path|f` zusammen ab — Filter-Werte können nicht ohne Signatur-Bruch manipuliert werden. Anfragen ohne Filter haben kein `&f`, Signatur deckt nur den Pfad ab (bit-identisch zum Verhalten vor diesem Release).
- **`Signature::sign($path, ?string $extraPayload = null, ?string $key = null)`** und entsprechend `verify` akzeptieren einen optionalen extraPayload als zweites Argument. Production-Verhalten ohne extraPayload bleibt unverändert.
- **CDN-URL-Template gewinnt `{f}`-Token** für den Filter-Blob (leer wenn keine Filter). Existing Templates ohne `{f}` produzieren identische URLs wie zuvor.
- **`Server::setActiveFilters` / `clearActiveFilters`** — neuer statischer Test-Seam, gesetzt von `Endpoint::handle` vor `makeImage`-Aufrufen, damit Glide's interne `Server::cachePath`-Aufrufe (über `cachePathCallable`) den gleichen On-Disk-Pfad produzieren wie unsere URL-Emission.
```

- [ ] **Step 2: Commit**

```bash
git add CHANGELOG.md
git commit -m "$(cat <<'EOF'
CHANGELOG: filter passthrough + asset-keyed cache layout

Two new Added entries (filter passthrough across PHP API + REX_PIC,
assets/cache/.gitignore) and six Changed entries documenting the
cache layout flip, new f{hash} segment, &f query param, Signature
extraPayload arg, CDN {f} token, and Server::setActiveFilters
test seam.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 14: CLAUDE.md — note new cache layout + setActiveFilters static-state pattern

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Update the cache-routing paragraph**

Find the `**Cache-path key has two shapes**` paragraph. Replace it with:

```markdown
**Cache-path key has four shapes** depending on whether the request involves crop and/or filters: `{src}/{fmt}-{w}-{q}.{ext}` (legacy), `{src}/{fmt}-{w}-{h}-{fitToken}-{q}.{ext}` (crop), `{src}/{fmt}-{w}-{q}-f{hash}.{ext}` (filters), `{src}/{fmt}-{w}-{h}-{fitToken}-{q}-f{hash}.{ext}` (crop + filters). The cache layout is **asset-keyed** — source path is the directory portion (preserves any subdirectories from the mediapool layout), transform spec is the basename's stem. `fitToken` is `cover-{X}-{Y}` / `contain` / `stretch`. `{hash}` is the first 8 hex chars of `md5(json_encode(ksort($filterParams)))`. `Endpoint::parseCachePath` accepts all four shapes; `Endpoint::handle` translates `cover-X-Y` to Glide's `crop-X-Y` at the boundary, and `Server::cachePath` normalizes the reverse direction so both call sites produce the same on-disk path.
```

- [ ] **Step 2: Add a new gotcha**

In the "REDAXO API gotchas (collected the hard way)" section, append:

```markdown
- **`Glide\Server::$activeFilterParams` is request-scoped static state.** `Endpoint::handle` sets it via `Server::setActiveFilters($filterParams)` before each `makeImage` call so the `cachePathCallable` closure (invoked internally by Glide for cache lookups) produces paths consistent with the URL emission's filter hash. `clearActiveFilters` runs in a `finally` block to avoid leaking state between hypothetical multi-handle runs in one process. Tests that exercise filtered Glide paths must reset this in `tearDown` — `Server::clearActiveFilters()` is public for that reason. The static-state approach is acceptable here because (a) `handle()` is the single caller in production, (b) it `exit`s after one request, and (c) `clearActiveFilters` runs unconditionally via `finally`. If a future change introduces multiple concurrent Glide pipelines in one PHP process, this pattern needs revisiting (instance state on a per-Server-instance basis would replace it).
```

- [ ] **Step 3: Update the architecture file tree**

Find the `lib/` ASCII tree. Find the `Glide/` block:

```
├── Glide/                                 # league/glide integration
│   ├── Server.php                         # factory, cache path callable
│   ├── ColorProfile.php                   # custom manipulator (sRGB)
│   ├── Endpoint.php                       # cache-URL handler (HMAC verify + Glide makeImage + send)
│   ├── RequestHandler.php                 # PACKAGES_INCLUDED hook → Endpoint::handle for self-contained routing
│   └── Signature.php                      # HMAC sign + verify
```

Add a `FilterParams.php` line:

```
├── Glide/                                 # league/glide integration
│   ├── Server.php                         # factory, cache path callable, setActiveFilters
│   ├── ColorProfile.php                   # custom manipulator (sRGB)
│   ├── Endpoint.php                       # cache-URL handler (HMAC verify + Glide makeImage + send)
│   ├── FilterParams.php                   # filter translation map + clamping + hex validation
│   ├── RequestHandler.php                 # PACKAGES_INCLUDED hook → Endpoint::handle for self-contained routing
│   └── Signature.php                      # HMAC sign + verify (optional extraPayload arg)
```

- [ ] **Step 4: Commit**

```bash
git add CLAUDE.md
git commit -m "$(cat <<'EOF'
CLAUDE.md: filter cache layout + setActiveFilters static-state pattern

- Cache-routing paragraph now describes all four cache-path shapes
  (legacy, crop, filters, crop+filters) and notes the asset-keyed
  layout convention plus the cover-X-Y / crop-X-Y normalization
  flow.

- New gotcha documenting Server::\$activeFilterParams as request-
  scoped static state set by Endpoint::handle, with rationale
  (handle exits after one request, clearActiveFilters runs in a
  finally) and a future-contributor warning.

- Architecture file tree updated to include FilterParams.php and
  reflect the expanded responsibilities of Server.php and
  Signature.php.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 15: Full-suite green + live Herd verification + bug-injection sanity

**Files:** none (verification only)

- [ ] **Step 1: Run the full test suite**

```bash
cd /Users/yvestorres/Repositories/massif_img
vendor/bin/phpunit
```
Expected: `OK (~70 tests, ~150 assertions)`.

- [ ] **Step 2: Time the unit suite**

```bash
time vendor/bin/phpunit --testsuite=unit
```
Expected: `OK (...)` in well under 5 seconds wall-clock.

- [ ] **Step 3: Live Herd verification — apply each filter category**

Manually create test slices on `viterex-installer-default.test` (or use the inline-API test endpoint pattern from earlier sessions):

- `REX_PIC[src="img_4587.jpeg" width="800" filter="sepia"]` — assert HTML contains `cache/img_4587.jpeg/jpg-800-80-f{hash}.jpg` and the rendered image is sepia-toned.
- `REX_PIC[src="img_4587.jpeg" width="800" brightness="20" sharpen="15"]` — assert two filters produce one combined hash, image visibly brighter and slightly sharper than baseline.
- `REX_PIC[src="img_4587.jpeg" width="800" ratio="1:1" fit="contain" bg="ffffff"]` — assert the contain'd image is on a white background fill.
- `REX_PIC[src="img_4587.jpeg" width="800" mark="logos/brand.png" markpos="bottom-right" markalpha="70"]` — assert watermark visible at bottom-right; if `logos/brand.png` doesn't exist in the mediapool, the variant 404s (acceptable, logged via `rex_logger`).

For each successful render, hit the cache URL directly via curl to confirm a 200 response. Re-hit to confirm the static fastpath kicks in (nginx-format ETag, no `X-Powered-By` header).

- [ ] **Step 4: Bug-injection sanity (one-time)**

Edit `lib/Glide/Server.php`. Find the line:

```php
            ksort($filterParams);
```

inside `cachePath`. Comment it out. Run:

```bash
vendor/bin/phpunit --filter=testCachePathFilterHashIsDeterministic
```
Expected: **FAIL.** Two insertion orders produce different hashes. Restore the line. Re-run; expected: PASS.

- [ ] **Step 5: Composer install --no-dev sanity**

```bash
composer install --no-dev
./bin/check-vendor
git status
```
Expected: `bin/check-vendor` reports clean. `git status` reports `nothing to commit, working tree clean` (dev packages were never committed).

- [ ] **Step 6: Restore dev for any further work**

```bash
composer install
```

No commit for this task.

---

## Self-review

**Spec coverage:**

| Spec section | Covered by |
|---|---|
| Fit enum (already landed in cropping plan) | n/a |
| FilterParams class + translation map | Task 1 |
| 12 builder methods | Task 4 |
| `Image::picture` `$filters` arg | Task 8 |
| 19 REX_PIC attributes | Task 9 |
| Asset-keyed cache layout (`{src}/{transform}.{ext}`) | Task 2 |
| Optional `f{hash}` cache-key segment | Task 2 |
| `Server::cachePath` + four shapes | Task 2 |
| `Endpoint::parseCachePath` four shapes | Task 2 |
| `Signature::sign` extraPayload | Task 3 |
| URL `&f=` query param | Task 6 |
| `Endpoint::handle` decode + hash validation + setActiveFilters | Task 7 |
| `Server::setActiveFilters` static + cachePathCallable read | Task 7 |
| `UrlBuilder` accepts filterParams + signs path\|f | Task 6 |
| `PictureRenderer` threads filterParams | Tasks 5 + 6 (bundled) |
| `Preloader` preserves filterParams | Task 6 |
| CDN URL template `{f}` token | Task 6 |
| Validation strategy (clamp / hex / passthrough) | Task 1 (FilterParams) + Task 4 (builder) |
| Watermark composite method | Task 4 |
| `assets/cache/.gitignore` | Task 10 |
| README Filter + Watermark + URL-Schema | Task 12 |
| CHANGELOG | Task 13 |
| CLAUDE.md updates | Task 14 |
| Verification (suite + bug-injection + live Herd) | Task 15 |

**Placeholder scan:** No `TBD` / `TODO` / `implement later` / `add appropriate error handling` / `similar to Task N` patterns. Every step has actual code or actual command + expected output.

**Type / signature consistency:**
- `FilterParams::FRIENDLY_TO_GLIDE`, `RANGES`, `HEX_PARAMS` constants defined in Task 1, used in Tasks 4, 9.
- `FilterParams::normalize($params): array` — Task 1, used in Task 4 (`filters()` bulk applier) and Task 8 (`Image::picture` forwarding).
- `FilterParams::clamp($glideParam, $value)` — Task 1, used in Task 4 (per-setter clamping).
- `Server::cachePath($path, $params)` — `params` keys: `fm`, `w`, `q`, `h`, `fit`, `filters`. Defined in Task 2 (cachePath) and Task 7 (`cachePathCallable` spread).
- `Endpoint::parseCachePath` return shape: `['fmt', 'w', 'q', 'h', 'fit', 'hash', 'source']`. Defined in Task 2; consumed in Task 7's `handle()`.
- `Signature::sign($path, ?$extra, ?$key)` and `verify($path, $sig, ?$extra, ?$key)` — Task 3; used in Task 6 (UrlBuilder) and Task 7 (Endpoint).
- `UrlBuilder::build($image, $w, $fmt, $q, $h, $fitToken, $filterParams)` — Task 6. Called from Task 5 (PictureRenderer::buildSrcset and fallback).
- `Server::setActiveFilters($params)` and `clearActiveFilters()` — Task 7. Used by Endpoint::handle in same task; tests use them in Tasks 11 (FilterPipelineTest tearDown).
- REX_PIC attribute keys (`brightness`, `mark`, etc.) defined in Task 1's `FRIENDLY_TO_GLIDE`, listed in Task 9's `$filterAttrs`, documented in Task 12's README tables.

---

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-05-02-filter-passthrough.md`. Two execution options:

**1. Subagent-Driven (recommended)** — I dispatch a fresh subagent per task, review between tasks, fast iteration

**2. Inline Execution** — Execute tasks in this session using executing-plans, batch execution with checkpoints

Which approach?
