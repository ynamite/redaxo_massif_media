# CLAUDE.md

Guidance for Claude Code working in this repository.

## Project

**MASSIF Media** is a standalone REDAXO 5 addon for responsive image and video rendering.

- REDAXO package: `massif_media`
- PHP namespace: `Ynamite\Media\` → `lib/`
- Baseline: PHP 8.2+
- Origin: split out from the old `redaxo-massif` kitchen-sink addon
- Coexists with `redaxo-massif`; there is **no migration shim**
  - legacy projects keep using `Ynamite\Massif\Media\...`
  - new code uses `Ynamite\Media\...`

Design spec, if needed: `/Users/yvestorres/.claude/plans/this-directory-is-a-luminous-candy.md`.

## Product scope

The addon provides:

- modern `<picture>` output with AVIF/WebP/JPG browser negotiation
- SVG/GIF passthrough
- responsive video rendering
- on-demand resizing through `league/glide` with Imagick
- HMAC-signed cache URLs to prevent disk-fill abuse
- optional CDN URL generation for ImageKit / Cloudinary / Imgix-style templates
- optional external HTTPS image sources with SSRF protection, local origin caching, TTL, and conditional GET
- LQIP placeholders, dominant colour extraction, focal-point support, and preload injection
- backend settings under **AddOns → MASSIF Media → Einstellungen**
- backend docs under **AddOns → MASSIF Media → Dokumentation**, rendered from `README.md`
- editor-facing `REX_PIC[...]` and `REX_VIDEO[...]` placeholders via native `rex_var`

## Core invariants

Do not casually change these. They are load-bearing.

### Public API

- `Image::picture()` returns `<picture>` markup.
- `Image::url()` returns a single generated image URL for posters, OG tags, CSS backgrounds, etc.
- `Video::render()` returns `<video>` markup.
- The image and video APIs are intentionally asymmetric:
  - `Image::picture(..., preload: bool)` controls head preload injection.
  - `Video::render(..., preload: 'none|metadata|auto', linkPreload: bool)` separates the HTML `preload` attribute from `<link rel="preload">`.
- Do not unify these names or parameter shapes for aesthetics; that would break call sites and semantics.

### Source model

All image input flows through `SourceInterface`:

```php
key(): string
absolutePath(): string
cacheBust(): string
isExternal(): bool
```

Every cache key, URL, placeholder, colour, endpoint, and metadata read must be based on `source.key()` plus `source.cacheBust()`. Do not reintroduce filename/mtime-only logic.

Supported source types:

- mediapool file / `rex_media` → `MediapoolSource`
- HTTPS URL → `ExternalSource`

### Cache routing

Cache URL routing is self-contained.

- Runtime cache path: `rex_path::addonAssets('massif_media', 'cache/')`
- Cache misses are handled by `Glide\RequestHandler` via `PACKAGES_INCLUDED` with `rex_extension::EARLY`.
- The addon must work without `.htaccess` or nginx config.
- Apache/nginx snippets are optional fastpaths for cache hits only.
- When adding support for another web server, prefer the PHP hook. Only add server config for proven cache-hit performance wins.

Request-handler pattern:

1. return immediately in backend
2. cheaply check URI prefix first
3. clean output buffers
4. `session_abort()`
5. handle response
6. `exit`

### Cache path shapes

Endpoint parsing must keep supporting all four shapes:

```text
{src}/{fmt}-{w}-{q}.{ext}
{src}/{fmt}-{w}-{h}-{fitToken}-{q}.{ext}
{src}/{fmt}-{w}-{q}-f{hash}.{ext}
{src}/{fmt}-{w}-{h}-{fitToken}-{q}-f{hash}.{ext}
```

Rules:

- `{src}` is the asset key / directory part.
- `fitToken` is `cover-{X}-{Y}`, `contain`, or `stretch`.
- filter hash comes from `CacheKeyBuilder::hashFilterParams()`.
- filter blob for `&f=` comes from `CacheKeyBuilder::encodeFilterBlob()`.
- fit/focal token logic comes from `FitTokenBuilder`.
- Do not duplicate these formulas elsewhere.

### External URLs

External images are cached under:

```text
cache/_external/<urlHash>/
```

- `_external/` is reserved and structurally safe: REDAXO mediapool filenames cannot start with `_`.
- External sources bypass the CDN branch; the upstream may already be a CDN.
- Use a per-bucket Glide server via `Server::createForExternal($source)`.
- `ExternalManifest` is the source of truth for URL, ETag, Last-Modified, fetchedAt, and TTL.
- `ExternalSourceFactory::resolveByHash()` is the endpoint read path and must not perform network IO.
- Conditional GET `304` must still bump `fetchedAt`.
- External fetch is synchronous on first render / expired TTL. Keep this trade-off unless a queue/placeholder system is introduced.

### Rendering order

For `<picture>` output:

- art-direction `<source media="...">` entries must come **before** default format sources
- the fallback `<img>` always uses the default variant
- builder-level filters do **not** cascade into art-direction variants; each art variant owns its own `filterParams`

## Directory map

```text
lib/
├── Image.php, Pic.php, Video.php
├── Builder/
│   ├── ImageBuilder.php
│   └── VideoBuilder.php
├── Source/
│   ├── SourceInterface.php
│   ├── MediapoolSource*.php
│   ├── ExternalSource*.php
│   ├── ExternalManifest.php
│   ├── HttpFetcher.php
│   └── SsrfGuard.php
├── Pipeline/
│   ├── ImageResolver.php
│   ├── MetadataReader.php
│   ├── ResolvedImage.php
│   ├── SrcsetBuilder.php
│   ├── UrlBuilder.php
│   ├── Placeholder.php
│   ├── DominantColor.php
│   ├── AnimatedWebpEncoder.php
│   ├── CacheStats.php
│   ├── CacheInvalidator.php
│   ├── RenderContext.php
│   └── Preloader.php
├── View/
│   ├── PictureRenderer.php
│   ├── PassthroughRenderer.php
│   └── ArtDirectionVariant.php
├── Glide/
│   ├── Server.php
│   ├── Endpoint.php
│   ├── RequestHandler.php
│   ├── Signature.php
│   ├── CacheKeyBuilder.php
│   ├── FitTokenBuilder.php
│   ├── FilterParams.php
│   ├── ColorProfile.php
│   └── StripMetadata.php
├── Var/
│   ├── RexPic.php
│   └── RexVideo.php
├── Config.php
├── Enum/
└── Exception/

pages/
├── index.php
├── settings.php
├── settings.general.php
├── settings.placeholder.php
├── settings.cdn.php
└── settings.security.php
```

## Development conventions

- Use PHP 8.2+ features: `readonly`, enums, named args.
- PSR-4 comes from `composer.json`.
- Run `composer dump-autoload` after adding classes.
- `vendor/` is committed so REDAXO Connect ZIP installs work without `composer install`.
- Before committing release vendor changes, run `composer install --no-dev` and `bin/check-vendor`.
- User-facing strings are German.
- Code identifiers are English.
- Defaults should be good enough that most installs do not touch settings.
- Keep `README.md`, `CHANGELOG.md`, and `CLAUDE.md` in sync with code/convention changes.

## Tests

Commands:

```bash
composer test              # full suite
composer test:unit         # fast, no Glide / FS
composer test:integration  # Glide + temp FS, slower
```

Expectations:

- new pure logic → unit test under `tests/Unit/`, mirroring source layout
- new public API entry point → at least one integration test
- Glide/cache-path/filter/focal logic needs regression coverage
- REDAXO frontend boot path is manually verified, not unit-tested

Manual verification target:

```text
~/Herd/viterex-installer-default/
```

Manually check:

- `RequestHandler::handle`
- `OUTPUT_FILTER` preload injection
- backend Documentation tab
- article-cache-bound `rex_var` behaviour after changing `getOutput()`

## REDAXO rules and gotchas

### Backend pages

- If `package.yml` declares `subpages:`, `pages/index.php` must exist.
- `pages/index.php` should echo the title and call `rex_be_controller::includeCurrentPageSubPath()`.
- Nested settings pages use the same dispatcher pattern in `pages/settings.php`.
- Settings subpages are named `pages/settings.{name}.php`.
- `subPath: README.md` in `package.yml` can render Markdown directly as a backend page.

### Requests and config forms

- `rex_request::isPost()` does not exist. Use `rex_request_method() === 'post'`.
- Use `rex_config_form::factory($addon)` for settings. Do not hand-roll forms.
- Store complex config as scalars and parse through typed accessors in `Config.php`.
- `addTextField()` adds `form-control`; `addInputField('number', ...)` does not.
- For number inputs, add:
  - `class="form-control"`
  - narrow inline width, e.g. `width: 100px` or `140px`
  - placeholder from `Config::DEFAULTS[...]`

### Namespaces

In namespaced files:

- import REDAXO classes explicitly: `use rex_url;`, `use rex_path;`, `use rex_media;`, etc.
- global functions such as `rex_post`, `rex_get`, `rex_request_method` do not need imports

### File/cache helpers

- `rex_dir::delete($path, false)` deletes contents but keeps the directory.
- Use this for cache-clearing hooks so the cache dir remains writable.
- `installAssets()` silently drops files starting with `.git`.
- Runtime `.gitignore` files under addon assets must be written from `install.php` via `rex_file::put()`.

### `rex_var`

Native `REX_PIC` / `REX_VIDEO` substitution is article-cache-bound.

- `getOutput()` returns PHP code baked into REDAXO's article cache.
- Changing `getOutput()` requires clearing the REDAXO cache for stored slices to pick up changes.
- Use `getParsedArg()` for string passthrough that may contain nested REX_VARs.
- Use `getArg()` for mode flags / raw values that must be inspected first.

Important examples:

- `as="url"` must use `getArg('as')`, not `getParsedArg('as')`.
- `preload` mode flags must use `getArg()`.
- `REX_PIC art='[...]'` JSON must use `getArg('art')`, then `json_decode(..., JSON_THROW_ON_ERROR)`.
- Bad art JSON should log a warning and render without art direction, not 500.
- Nested poster recipe:

```text
REX_VIDEO[poster="REX_PIC[src='hero.jpg' width='1280' as='url']"]
```

## Glide and media gotchas

### Glide closures

`setCachePathCallable()` requires a non-static closure and must not reference `self::` or `static::` inside the closure body.

Use:

```php
Server::cachePath(...)
// or
\Ynamite\Media\Glide\Server::cachePath(...)
```

Reason: Glide binds the closure to `League\Glide\Server`, which breaks static closures and rescopes `self::` / `static::`.

### Fit and focal points

- Glide accepts `fit=crop-X-Y`, but X/Y must be integers.
- Always use `FitTokenBuilder::build()`.
- Do not emit decimal focal coordinates.
- `fit=contain` does not letterbox; it only scales proportionally inside the requested box.

### Filter state

`Glide\Server::$activeFilterParams` is request-scoped static state.

- `Endpoint::handle()` sets it before `makeImage()`.
- `clearActiveFilters()` must run in `finally`.
- Tests that touch filtered Glide paths should reset it in `tearDown()`.

### Animated images

Glide encodes only the first frame of animated GIFs.

- Animated WebP must use `AnimatedWebpEncoder`.
- Use `Imagick::coalesceImages()` and `writeImages($path, true)`.
- Animated output is mediapool-only; external sources short-circuit.

### Colour handling

- Use `Imagick::COLORSPACE_SRGB` for user-visible colour extraction.
- Do not use `COLORSPACE_RGB`; it produces linear-light values that look too dark.
- Use `ImagickPixel::getColor(2)` to get 0–255 channel values.

### Focal-point metadata

The optional `focuspoint` addon stores focal points in `rex_media.med_focuspoint`; changing it does not touch file mtime.

Therefore:

- metadata/focal caches must not rely only on filename+mtime
- `MEDIA_UPDATED` and `MEDIA_DELETED` must call `CacheInvalidator::invalidate($filename)`
- old tiny sidecar orphans after file replacement are accepted; global cache clear removes them

### Video

- Preload MIME map:
  - `mp4`, `m4v` → `video/mp4`
  - `webm` → `video/webm`
  - `ogv`, `ogg` → `video/ogg`
  - `mov` → `video/quicktime`
- Unknown video extensions should omit the `type` attribute instead of guessing.
- Broken `<video poster>` URLs can collapse layout in WebKit/Blink.
- `VideoBuilder::validatePoster()` should drop definitely missing bare mediapool filenames.
- Existing bare filenames that do exist are still emitted as-is; symmetric poster resolution is out of scope for now.
- Recommended poster recipe: pass a generated URL via `Image::url(...)` or nested `REX_PIC[..., as='url']`.

## Config gotchas

### Checkboxes

`rex_config_form::addCheckboxField()` has three important storage states:

1. ticked → pipe-delimited string, e.g. `|1|`
2. unticked → `null`
3. never written → missing key, should fall back to defaults

Do not cast checkbox values with `(bool) (int)`. Use `Config::checkboxBool($key)`.

`rex_config::has()` cannot distinguish explicit `null` from a missing key. Use `array_key_exists($key, rex_config::get(ADDON))` when that distinction matters.

### Cache invalidation

- `CacheInvalidator::invalidate($filename)` deletes the variant directory and current `_meta`, `_lqip`, `_color` sidecars.
- `CacheInvalidator::invalidateUrl($url)` drops the full external URL bucket.
- Variant directories are path-keyed and are the bulky part.
- Tiny old sidecar orphans after file replacement are accepted.

## External fetch rules

- SSRF guard uses IPv4 `gethostbynamel()` and rejects loopback/private/link-local/CGNAT/multicast/broadcast ranges.
- The resolved IPv4 is passed through Symfony's `resolve` option to pin the connection and reduce DNS-rebinding risk.
- IPv6-only hosts currently fail validation. Add proper IPv6 block-listing before supporting them; do not disable SSRF protection.
- Enforce max body size in Symfony HTTP Client through `on_progress` throwing `TransportException`.
- Do not read-then-truncate large responses.
- Symfony request headers may appear either associative or as `Name: value` strings in tests; assertions must tolerate both shapes.
- `$response->getHeaders(false)` returns `array<string, list<string>>`.

## Common operations

### Add public API

- add method to `lib/Image.php` or `lib/Video.php`
- add matching builder method under `lib/Builder/`
- add integration test
- update README / CHANGELOG / CLAUDE if behaviour or conventions change

### Change default config

- update `Config::DEFAULTS`
- update typed accessor if needed
- update settings form field if user-editable
- add/adjust tests for parsing behaviour

### Add settings field

- add key constant in `Config.php`
- add default
- add typed accessor
- add field to the right `pages/settings.{tab}.php`
- use `rex_config_form`

### Add settings tab

- declare under `settings:` → `subpages:` in `package.yml`
- create `pages/settings.{name}.php`
- follow the existing form + `rex_fragment('core/page/section.php')` pattern

### Add Glide manipulator

- add class under `lib/Glide/`
- register in both `Server::create()` and `Server::createForExternal()`
- add tests for mediapool and external paths if behaviour differs

### Add source type

- implement `SourceInterface`
- add factory under `lib/Source/`
- route in `ImageResolver::resolve()`
- update `Server::for()` and `Server::glideSourcePath()`
- if the filesystem root differs from the mediapool root, add a per-bucket server factory like `createForExternal()`

### Add extension point

- register in `boot.php`
- keep frontend hooks extremely cheap on non-matching requests

### Cut a release

1. update `version:` in `package.yml`
2. move `CHANGELOG.md` items from `[Unreleased]` to `## [x.y.z] — YYYY-MM-DD`
3. run tests locally; run full suite if Glide/pipeline changed
4. run `composer install --no-dev`
5. run `bin/check-vendor`
6. commit
7. create GitHub release:

```bash
gh release create vX.Y.Z --title "vX.Y.Z" --notes "<release notes>"
```

Release publishing is handled by `.github/workflows/publish-to-redaxo.yml` on `release: published`.

CI quality gate:

- PHP 8.2 setup
- `composer test:unit`
- `composer install --no-dev`
- `bin/check-vendor`
- ZIP build
- attach ZIP to GitHub release
- publish to my.redaxo.org

Required repo secrets:

- `MYREDAXO_USERNAME`
- `MYREDAXO_API_KEY`

Integration tests are not CI-gated; run them locally before tagging when touching Glide/cache/rendering.

## Out of scope / v2 candidates

- symmetric mediapool resolution for existing bare-filename video posters
- async external URL fetching / queue-backed prewarming
- true letterboxed `contain` output via image manipulation
- IPv6 support in `SsrfGuard`
- shared default filters for all art-direction variants
