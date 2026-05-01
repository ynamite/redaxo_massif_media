# Testing infrastructure — PHPUnit + unit / thin-integration suites

## Context

The addon currently has zero automated tests; verification is manual against `~/Herd/viterex-installer-default/`. CLAUDE.md codifies this as a hard rule (`**No tests**. Verification is manual`). That rule has been a real liability — three of the bugs caught in recent sessions (the Glide static-closure binding, the `self::cachePath` rebinding under `Closure::bind`, and the cover↔crop cache-path round-trip) would each have been caught by a single targeted unit test. They were instead found at runtime, in some cases after multiple commits had landed.

This spec replaces the no-tests rule with a pragmatic two-suite PHPUnit setup that pays for itself on the first real bug it catches. Scope is intentionally narrow: unit tests for the pure-logic core, thin integration tests for the Glide pipeline against a real filesystem with fixture images, no end-to-end REDAXO-frontend tests (manual Herd verification remains the gate for that layer). The non-goals — full pyramid, Docker REDAXO fixtures, GitHub Actions CI, code-coverage gates — are listed explicitly.

The intended outcome: `composer test` runs in seconds for unit, under a minute for integration; new features can be developed test-first under this infrastructure (the immediate next user being the Glide filter passthrough feature, brainstormed separately).

## Framework and tooling

- **PHPUnit ^11** as the runner (de facto standard for PHP 8.2+; Pest considered but rejected — extra dep layer, less common in REDAXO-land, no testability gain that matters here).
- Added via `composer require-dev phpunit/phpunit ^11`.
- Workflow: `composer install` locally to pull dev deps; `composer install --no-dev` before any commit touching `vendor/`. Documented in CLAUDE.md.
- `bin/check-vendor` — a small (~30-line) shell script that scans `vendor/` for known PHPUnit-family packages (`phpunit/`, `sebastian/`, `myclabs/`, `phar-io/`, `theseer/`, `phpdocumentor/`, `nikic/php-parser`) and exits non-zero if any are present. Run manually (or from a pre-commit hook if the maintainer wants enforcement). Lives in `bin/` so it can be referenced from CLAUDE.md without dropping a build artifact at repo root.

## Layout

```
tests/
├── Unit/                              # mirror of lib/, no REDAXO / no Glide
│   ├── Glide/
│   │   ├── EndpointTest.php           # parseCachePath both shapes, isValidFitToken, mimeFor
│   │   ├── ServerTest.php             # cachePath both shapes + crop↔cover normalization
│   │   └── SignatureTest.php          # sign / verify with injected key
│   ├── Pipeline/
│   │   ├── SrcsetBuilderTest.php      # default pool, override, intrinsic cap, effectiveMaxWidth cap
│   │   └── MetadataReaderHelpersTest.php  # normalizeFocal, formatFocal, formatFromMime
│   ├── Var/
│   │   └── RexPicTest.php             # parseRatio, focal-to-int, getOutput per attribute
│   └── Enum/
│       └── FitTest.php                # ::from round-trip + invalid value
├── Integration/                       # real Glide + temp FS, fixture images
│   ├── CropPipelineTest.php
│   ├── HmacRoundtripTest.php
│   └── MetadataReaderTest.php
├── _fixtures/
│   ├── landscape-800x600.jpg
│   ├── portrait-600x800.jpg
│   ├── square-400x400.png
│   ├── tiny-32x32.gif
│   ├── vector.svg
│   └── generate.php                   # one-shot regeneration script (committed)
├── _stubs/
│   └── redaxo.php                     # rex_config, rex_path, rex_url, rex_logger, rex_media, rex_dir, rex_file, rex
└── bootstrap.php                      # PSR-4 autoload + register stubs + strict error handler
```

`composer.json` gains `autoload-dev` mapping `Tests\\Massif\\Media\\` → `tests/`, ensuring test classes follow the same PSR-4 discipline as production code.

## phpunit.xml

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
    bootstrap="tests/bootstrap.php"
    colors="true"
    failOnWarning="true"
    failOnNotice="true"
    failOnDeprecation="true"
    cacheDirectory=".phpunit.cache"
    displayDetailsOnTestsThatTriggerWarnings="true"
    displayDetailsOnTestsThatTriggerNotices="true"
    displayDetailsOnTestsThatTriggerDeprecations="true"
>
    <testsuites>
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="integration">
            <directory>tests/Integration</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

Composer scripts:

```json
"scripts": {
    "test": "phpunit",
    "test:unit": "phpunit --testsuite=unit",
    "test:integration": "phpunit --testsuite=integration"
}
```

## Stub strategy

`tests/_stubs/redaxo.php` defines the smallest possible surface of REDAXO core classes that our `lib/` code touches. Each stub is a class with the methods we actually call, no more. Loaded conditionally — only if the real classes aren't already defined — so the same stub file works both standalone (PHPUnit alone) and against a future against-a-real-REDAXO test mode if we ever add one.

Concrete stubs needed:

- `rex_config` — `static get(string $namespace, string $key, $default = null)` reads from a static `$store` array. `static set(string $namespace, string $key, $value)` writes to it. Tests reset `$store` in `setUp()` via a public `_reset()` helper.
- `rex_path` — `static media(string $file = '')`, `static addonAssets(string $addon, string $relative = '')` return paths under a per-test-class temp directory created via `sys_get_temp_dir()` + uniqid. Cleaned up in `tearDownAfterClass`.
- `rex_url` — `static addonAssets(string $addon, string $relative = '')` returns `'/assets/addons/' . $addon . '/' . $relative`. Pure string concat, no real URL routing.
- `rex_logger` — `static logException(\Throwable $e)` is a no-op for tests. Optional `_lastException` slot for tests that want to assert exceptions were logged.
- `rex_media` — minimal stub with `getFileName()` and `getValue($key)`. Constructor takes filename + values map.
- `rex_dir` — `static delete(string $path, bool $deleteSelf = false)` recursive directory delete (real implementation, used in CACHE_DELETED tests if any).
- `rex_file` — `static put(string $path, string $contents)` real implementation. `static get(string $path)` real implementation.
- `rex` — `static isBackend()` returns false in tests by default. `_setBackend(bool $b)` for tests that need to flip it.

Total stub file size estimated at 100-150 lines of PHP. No third-party deps.

## Required production code refactors

Three tiny refactors keep the pipeline pure-testable without injecting REDAXO into every test:

### 1. `Signature` accepts an injected key

Current:
```php
public static function sign(string $path): string
{
    return hash_hmac('sha256', $path, Config::signKey());
}
```

New:
```php
public static function sign(string $path, ?string $key = null): string
{
    $key ??= Config::signKey();
    return hash_hmac('sha256', $path, $key);
}
```

Same change to `verify()`. Production callers don't pass a key; tests do. Backward compatible.

### 2. `Glide\Server::create()` accepts directory overrides

Current:
```php
public static function create(): GlideServer
{
    $sourceFs = new Filesystem(new LocalFilesystemAdapter(rex_path::media()));
    $cacheFs = new Filesystem(new LocalFilesystemAdapter(rex_path::addonAssets(Config::ADDON, 'cache/')));
    // ...
}
```

New:
```php
public static function create(?string $sourceDir = null, ?string $cacheDir = null): GlideServer
{
    $sourceDir ??= rex_path::media();
    $cacheDir ??= rex_path::addonAssets(Config::ADDON, 'cache/');
    $sourceFs = new Filesystem(new LocalFilesystemAdapter($sourceDir));
    $cacheFs = new Filesystem(new LocalFilesystemAdapter($cacheDir));
    // ...
}
```

Production callers don't pass dirs; integration tests do.

### 3. Promote pure helpers to `public` for direct unit testing

Three currently-private static methods are pure (no `$this`, no side effects) and need to be reachable from tests without reflection:

- `MetadataReader::normalizeFocal(string $value): ?string` — `private` → `public`.
- `MetadataReader::formatFocal(float $x, float $y): string` — `private` → `public`.
- `PictureRenderer::parseFocalToInts(?string $value): array` — `private` → `public static`.

Changing visibility is safe — these methods don't reference `$this` and have no production callers outside the same class. Promoting them to `public static` exposes a small testable surface without inventing a new helper class. Each change is a single keyword.

All three refactors are ≤5 lines each, additive only, no behavior change for production code.

## Unit test surface (targeted bugs each test would have caught)

- `EndpointTest::testParseCachePathLegacyShape` — sanity, the existing 3-token shape parses correctly.
- `EndpointTest::testParseCachePathCropShape` — crop 5+ token shape parses correctly; `cover-50-50` token reassembled from segments 3..N-1.
- `EndpointTest::testParseCachePathRejectsMalformed` — non-numeric tokens, missing extension, bogus fit names → null.
- `ServerTest::testCachePathLegacyVsCrop` — given the same args minus h/fit, shape stays legacy.
- `ServerTest::testCachePathRoundTripsCoverCrop` — `cachePath` invoked with `fit=crop-50-50` produces the same string as `cachePath` invoked with `fit=cover-50-50`. **This would have caught the bug fixed in commit 7cb0f2b.**
- `SignatureTest::testSignVerifyRoundtrip` — sign + verify with same key returns true.
- `SignatureTest::testVerifyRejectsTampered` — modified path → verify returns false.
- `SignatureTest::testVerifyRejectsWrongKey` — different key → verify returns false.
- `SrcsetBuilderTest::testIntrinsicCap` — pool ≤ intrinsic.
- `SrcsetBuilderTest::testEffectiveMaxWidthCap` — when set, pool ≤ effectiveMaxWidth.
- `SrcsetBuilderTest::testOverrideReplacesPool` — caller-supplied widths replace defaults.
- `MetadataReaderHelpersTest::testNormalizeFocalAcceptedFormats` — `"50% 30%"`, `"50,30"`, `"0.5,0.3"`, JSON `{x,y}`, malformed → null.
- `RexPicTest::testParseRatio` — `"16:9"` / `"16/9"` / `"1.7777"` → ~1.778; bad input → null.
- `RexPicTest::testGetOutputEmitsImagePictureCall` — full attribute matrix, output is a valid PHP expression starting with `\\Ynamite\\Media\\Image::picture(`.
- `RexPicTest::testGetOutputReturnsFalseOnMissingSrc` — required attribute enforcement.
- `FitTest::testFromValidValues` — `Fit::from('cover')` etc.
- `FitTest::testFromInvalidThrows` — `Fit::from('bogus')` raises `\ValueError`.

## Integration test surface

- `CropPipelineTest` — for each `Fit` mode (cover, contain, stretch, none) and the ratio-matches-intrinsic shortcut: instantiate a temp-dir `Glide\Server`, call `Image::picture(...)`, assert (a) HTML structure (single `<picture>`, three `<source>` plus `<img>`, srcset format), (b) cache file appears at the expected path, (c) generated image dimensions match the requested shape.
- `HmacRoundtripTest` — UrlBuilder emits → Endpoint parses + verifies, both legacy and crop URL shapes; tampered signature is rejected; mismatched key is rejected.
- `MetadataReaderTest` — read each fixture, assert intrinsic dims / mime / source_format. Blurhash assertion gated on `function_exists('imagecreatefromstring')`.

## Fixture images

Five small files committed under `tests/_fixtures/`. Generated via a one-shot `generate.php` script kept in the same directory for regeneration:

- `landscape-800x600.jpg` (~30 KB)
- `portrait-600x800.jpg` (~30 KB)
- `square-400x400.png` (~10 KB)
- `tiny-32x32.gif` (~1 KB, passthrough test)
- `vector.svg` (~500 bytes, passthrough test)

`generate.php` uses Imagick if available (preferred) else GD. Script does not run as part of the test suite — it's a one-time tool, run manually if image formats need to change. Fixture footprint stays under 100 KB total.

## Fail-fast posture

- `phpunit.xml`: `failOnWarning="true" failOnNotice="true" failOnDeprecation="true"` — PHP runtime warnings surface as test failures rather than silently passing.
- `tests/bootstrap.php` registers a strict error handler converting E_NOTICE / E_WARNING / E_DEPRECATED into `\ErrorException`s during the test run. Restored in shutdown to avoid affecting unrelated PHP processes that load `bootstrap.php` directly.
- `composer test:unit` performance expectation: under 5 seconds total on a modern laptop. Not a hard PHPUnit gate (PHPUnit doesn't have a global suite-wide time budget), just a documented threshold — if the unit suite ever crosses it, something is doing real Glide work that should have lived in the integration suite. Per-test annotations (`#[Group('slow')]`, `--exclude-group=slow`) are available if a borderline test needs to opt out of the unit suite later.

## CLAUDE.md changes (applied during implementation)

- **Remove**: the `**No tests**. Verification is manual` line under "Conventions".
- **Add** new sub-section under "Conventions" titled **Testing**:
  > Run with `composer test` (full), `composer test:unit` (fast, no Glide / FS), `composer test:integration` (Glide + temp FS, slower). Every new pure-logic function gets a unit test in `tests/Unit/` mirroring the source layout. Every new public-API entry point gets at least one integration test in `tests/Integration/`. Manual verification on `~/Herd/viterex-installer-default/` remains the gate for the REDAXO frontend boot path (`RequestHandler::handle`, `OUTPUT_FILTER` preload injection, the backend Documentation tab) — those layers are deliberately not unit-tested.
- **Add** to "REDAXO API gotchas":
  > **`vendor/` is committed but must be `--no-dev` for releases.** Local dev: `composer install` (resolves dev deps including PHPUnit). Before any commit touching `vendor/`: `composer install --no-dev` to strip dev packages. Run `bin/check-vendor` to verify the working tree is clean of dev packages — it scans for known PHPUnit-family directories under `vendor/` and exits non-zero if it sees them.

## Critical files to modify or create

- `composer.json` — add `require-dev`, `autoload-dev`, `scripts`.
- `phpunit.xml` — **new**.
- `bin/check-vendor` — **new**.
- `tests/bootstrap.php` — **new**.
- `tests/_stubs/redaxo.php` — **new**, ~150 lines of REDAXO class stubs.
- `tests/_fixtures/generate.php` — **new**, fixture-regeneration script.
- `tests/_fixtures/*.{jpg,png,gif,svg}` — **new** (committed binary fixtures).
- `tests/Unit/Glide/{Endpoint,Server,Signature}Test.php` — **new**.
- `tests/Unit/Pipeline/{SrcsetBuilder,MetadataReaderHelpers}Test.php` — **new**.
- `tests/Unit/Var/RexPicTest.php` — **new**.
- `tests/Unit/Enum/FitTest.php` — **new**.
- `tests/Integration/{CropPipeline,HmacRoundtrip,MetadataReader}Test.php` — **new**.
- `lib/Glide/Signature.php` — accept optional `?string $key` arg on `sign()` and `verify()`.
- `lib/Glide/Server.php` — accept optional `?string $sourceDir`, `?string $cacheDir` args on `create()`.
- `lib/Pipeline/MetadataReader.php` — promote `normalizeFocal()` and `formatFocal()` from `private` to `public`.
- `lib/View/PictureRenderer.php` — promote `parseFocalToInts()` from `private` to `public static`.
- `CLAUDE.md` — remove "No tests" rule, add Testing section + vendor-no-dev gotcha.
- `README.md` — light update mentioning `composer test` is available (one bullet under Anforderungen / Installation, no full Testing section — that's the maintainer's concern, not the user's).
- `CHANGELOG.md` — Added: PHPUnit test suite. Changed: Signature::sign/verify and Glide\\Server::create gain optional override args (additive, backward compatible).

## Reused functions / utilities

- `Filesystem` + `LocalFilesystemAdapter` from `league/flysystem-local` (already a transitive dep via `league/glide`) — used in tests to point Glide at a temp dir.
- PHPUnit's `tempnam()` / `sys_get_temp_dir()` for per-test scratch directories.
- The fixture-generate script reuses Imagick directly (already a project assumption — the addon requires it for AVIF anyway).
- `MetadataReader::normalizeFocal` / `formatFocal` are already pure helpers — tests exercise them directly without touching the rest of the class.

## Out of scope (explicit non-goals)

- End-to-end tests through a real REDAXO frontend boot. Manual Herd verification remains the gate for `RequestHandler::handle`, `OUTPUT_FILTER` injection, the backend Documentation tab, and the article-cache rebuild for `REX_PIC` slices.
- Docker-based REDAXO fixture installs. Considered (gives high-fidelity end-to-end coverage), rejected for v1 (heavyweight setup, slow runs, weak ROI for an addon this size).
- GitHub Actions / any CI. Local `composer test` is the gate. Revisit when external contributors join.
- Code coverage gates. PHPUnit will report coverage if requested (`--coverage-text`) but no minimum threshold is enforced.
- Pest / alternative frameworks. PHPUnit 11 is the standard; revisit only if the maintainer explicitly wants nicer syntax.
- Performance / benchmark tests. Out of scope for correctness coverage.

## Verification

The test suite verifies itself two ways:

1. **Run-and-pass**: after implementation, `composer test` returns exit 0 with all assertions passing. Both suites (`unit`, `integration`) run cleanly.
2. **One-time bug-injection sanity** (run during implementation, results recorded in the implementation plan):
   - Revert the cover↔crop normalization in `Server::cachePath` (`crop-X-Y` → `cover-X-Y`). Run `composer test:unit`. **`ServerTest::testCachePathRoundTripsCoverCrop` must fail.** Restore.
   - Flip `count($tokens) === 3` to `=== 4` in `Endpoint::parseCachePath`. Run `composer test:unit`. **`EndpointTest::testParseCachePathLegacyShape` must fail.** Restore.

These two injections aren't permanent tests — they're a one-time confirmation the suite would have caught real bugs. Documented in the plan's verification step, run once.

A third softer verification: post-implementation, when developing the Glide filter passthrough feature (next spec), the tests should be the first thing written for any new public API surface. If the filter spec's plan doesn't lead with "write the test, then write the code", the testing infrastructure isn't really doing its job.
