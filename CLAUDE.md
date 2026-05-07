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

#### `_img/.bootstrap.php` is generated, not shipped

`assets/_img/index.php` is the cache-miss entry point when `.htaccess` rewrites missing files into PHP. It `require`s `_img/.bootstrap.php`, which `install.php` regenerates on every install/reinstall via the live path provider. The generated bootstrap MUST set:

- `$REX['REDAXO']` (false for frontend)
- `$REX['HTDOCS_PATH']`, `$REX['BACKEND_FOLDER']`
- `$REX['PATH_PROVIDER']` if a custom provider is in use (Viterex `app_path_provider`, etc.) — REQUIRED, not optional, on those layouts
- `$REX['LOAD_PAGE'] = true` — without this, `core/boot.php` finishes framework setup at line 134 and returns; the `frontend.php` → `packages.php` chain that registers and boots addons never runs, and `rex_addon::get('massif_media')` returns the null-stub. Calling `->boot()` on the null-stub fatals (`Call to undefined method rex_null_addon::boot()`).

`RequestHandler` registered on `PACKAGES_INCLUDED` with `EARLY` priority intercepts the request during the dispatch inside `packages.php` and `exit`s before `frontend.php` continues to its sendPage path. The defensive checks in `_img/index.php` (after the bootstrap require) are unreachable in the success path but stay as a diagnostic safety net.

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

### Composer autoloader

We ship `vendor/` so Connect-installs work without `composer install`. The crucial gotcha:

- **REDAXO scans every addon's `vendor/` recursively and indexes every class it finds.** `rex_addon::enlist()` calls `rex_autoload::addDirectory($addon . 'vendor')` (REDAXO core `lib/packages/package.php:392`), which walks the tree and populates `rex_autoload::$classes` with `class-name => file-path` entries. First write wins (`if (!isset(self::$classes[$class]))`).
- `rex_autoload::autoload()` consults that index **before** falling back to REDAXO core's bundled Composer loader. SPL chain priority on our own `ClassLoader` therefore does **not** decide which file resolves a class shared with REDAXO core — REDAXO's class index does, and it was populated as soon as our `vendor/` was enlisted.
- Practical consequence: **any package version we ship that overlaps with REDAXO core's `composer.json` must match the signature REDAXO core was authored against**, or PHP fatals when REDAXO's class index points at our file. The canonical failure is `psr/log`: `rex_logger extends AbstractLogger`, so the indexed `Psr\Log\AbstractLogger` must agree with what `rex_logger::log()` declares. REDAXO 5.18+ ships `psr/log: ^3.0.2` (verified on tags `5.18.0` through `5.21.0` of `redaxo/redaxo` `redaxo/src/core/composer.json`), so we pin `psr/log: ^3.0` in our `composer.json` to ship the matching v3 signature (`string|\Stringable $message`, `: void`). When REDAXO core's pin moves, bump ours in lockstep — this is also why the addon's minimum REDAXO version is `^5.18.0` and not lower, since REDAXO 5.13–5.17 ships v1 and the two signatures are LSP-incompatible.
- Overlap list with REDAXO core (currently: `psr/log`, `symfony/console`, `symfony/yaml`, `symfony/var-dumper`, `symfony/http-foundation`, `voku/portable-utf8`, `enshrined/svg-sanitize`, `erusev/parsedown`, `composer/ca-bundle`). Avoid adding new requirements from this list. If you must, pin to the version REDAXO core declares — there's no SPL-priority workaround that makes a mismatched signature safe under `rex_autoload`.
- `boot.php` can therefore use a plain `require __DIR__ . '/vendor/autoload.php';` — Composer's default prepended SPL registration is fine because the conflict was never resolved at the SPL layer.

### Install / activate cache invalidation

REDAXO's `package_manager::install()` and `activate()` do **not** call `rex_delete_cache()` — only `uninstall()`, `deactivate()`, and `delete()` do.

- Slices and templates cached **before** the addon was active still contain `REX_PIC[…]` / `REX_VIDEO[…]` as literal text (the var wasn't registered when `rex_var::parse` ran).
- `install.php` must call `rex_delete_cache()` at the end so the article cache regenerates with our vars active on next render.
- This also fires on reinstall / update, which is the right behaviour when `getOutput()` semantics drift between releases (existing baked-in PHP would otherwise call into the new `Image::picture()` signature with stale arg shape).

### `rex_var`

Native `REX_PIC` / `REX_VIDEO` substitution is article-cache-bound.

- `getOutput()` returns PHP code baked into REDAXO's article cache.
- Changing `getOutput()` requires clearing the REDAXO cache for stored slices to pick up changes.
- Use `getParsedArg()` for string passthrough that may contain nested REX_VARs.
- Use `getArg()` for mode flags / raw values that must be inspected first.

Important examples:

- `as="url"` must use `getArg('as')`, not `getParsedArg('as')`.
- `preload` mode flags must use `getArg()`.
- `REX_PIC art='{...}'` JSON must use `getArg('art')`, then `json_decode(..., JSON_THROW_ON_ERROR)`. The slice-content shape is a JSON **object** (`{"sm": {...}, "md": {...}}`), not a list — REDAXO's `rex_var` tokenizer regex (`var.php::getMatches`) bars unescaped `[`/`]` inside the tag, so a list-shape `art='[...]'` would prevent the entire `REX_PIC[...]` from being recognised. `buildArtArg` accepts both shapes (it `array_values()`s the decoded value before per-entry validation), so direct PHP callers of `Image::picture(art: [...])` keep the natural list form.
- Bad art JSON should log a warning and render without art direction, not 500.
- Nested poster recipe:

```text
REX_VIDEO[poster="REX_PIC[src='hero.jpg' width='1280' as='url']"]
```

#### Two parse paths, not one

`REX_PIC[…]` / `REX_VIDEO[…]` reach output through two different mechanisms — they look identical to the editor but behave differently in edge cases:

1. **Cache-build path** (`Var/RexPic`, `Var/RexVideo`): runs when REDAXO calls `rex_var::parse()` on a module/article template. `getOutput()` returns PHP code baked into the article cache; the rendering happens at request time but the parsing happened at slice-save / cache-build. Supports nested REX_VARs (`REX_VIDEO[poster="REX_PIC[…]"]`) because REDAXO's tokenizer recurses.

2. **Post-render scan** (`View/EditorContentScanner`): runs in the `OUTPUT_FILTER` extension point in `boot.php`. Scans the final rendered HTML, regex-matches each `REX_PIC[…]` / `REX_VIDEO[…]` substring, and replaces with `<picture>` / `<video>` markup. Necessary because `rex_var::parse()` is never called on slice values, MetaInfo text, or YForm rich-text fields — only on module/article templates (`article_content_base.php:523`, `cache_template.php:29`). Without this pass, a tag typed into a rich-text editor stays literal in the rendered page.

The post-render scan does NOT support:
- Nested REX_VARs inside attribute values (template-level vars have already resolved by output-filter time, so any nested REX_VAR in editor input never went through the cache-build pass either).
- `[` or `]` inside attribute values (same constraint as REDAXO's tokenizer).

Behavior on edge cases (post-render scan):
- Missing `src` → log warning, leave literal in place.
- `Image::picture` / `Video::render` throws → log via `rex_logger::logException`, leave literal in place.
- Empty render result (resolver couldn't load source, no usable widths) → leave literal in place. Different from the cache-build path, where empty silently emits empty — editor-input context favours visible failure so the editor can spot the typo.

The scanner cheap-skips with two `stripos` calls when neither marker substring is present, so pages without editor REX_VARs cost effectively zero. Pre-built `<picture>` markup from the cache-build path no longer matches the regex, so the two paths don't conflict.

#### `OUTPUT_FILTER` ordering

`boot.php` registers a single `OUTPUT_FILTER` closure that does two things in sequence:

1. `EditorContentScanner::scan($subject)` — replaces editor REX_PIC/REX_VIDEO literals.
2. `Preloader::drain()` + `</head>` injection.

Order matters: a `REX_PIC[..., preload="true"]` in editor content invokes `Image::picture(..., preload: true)` during the scan, which queues a preload `<link>` via `Preloader::queue()`. That queue MUST drain before the `</head>` injection runs — keep step 1 before step 2.

## Glide and media gotchas

### Cache filesystem permissions

Three things have to align for cache files to be readable by Apache on shared hosting (Plesk, cPanel — PHP-FPM and Apache run as different users):

1. **Flysystem visibility = PUBLIC.** `LocalFilesystemAdapter`'s default `PortableVisibilityConverter` uses `Visibility::PRIVATE` for new directories (mode 0700) and 0644 for files but only if explicitly set in the write config. We pass `PortableVisibilityConverter::fromArray([], Visibility::PUBLIC)` via `Server::publicVisibility()` to set the *intent* to 0755 dirs / 0644 files. Source filesystems stay on the default — REDAXO's mediapool perms aren't ours to set.
2. **`umask(0022)` for the request body.** `LocalFilesystemAdapter::ensureDirectoryExists` calls `mkdir($path, 0755, true)` and `LocalFilesystemAdapter::writeToFile` calls `file_put_contents` without an explicit chmod after — so the process umask masks both. With umask 027 (Plesk default), the `mkdir(0755)` actually creates 0750 and Apache (different user) cannot traverse. `Endpoint::handle` wraps its body with `umask(0022)` / restore-in-finally so visibility intent and actual mkdir mode align.
3. **`install.php` migration.** The two above only affect *new* writes. Existing variant directories created before the fix shipped (currently 0700 on vincafilm.ch) need a one-shot recursive `chmod` to 0755 dirs / 0644 files. Migration runs on every install/reinstall, silently skips unfixable subtrees (`@chmod`).

Symptom of breakage: `[core:crit] (13)Permission denied: AH00529: ... pcfg_openfile: unable to check htaccess file, ensure it is readable and that ... is executable` in the Apache error log, plus 403s on cache hits. Apache walks up the directory tree on every request looking for .htaccess at every level; if any directory along the way is mode 0700, it 403s preemptively even though no `.htaccess` exists there.

### Encoder capability detection

`Config::canServerEncode()` decides whether AVIF / WebP `<source>` elements are emitted. It mirrors **Glide's own driver selection** — Imagick when the extension is loaded, GD otherwise (`Glide\Server::create` line 94 / 155, matching `intervention/image` v3's two-driver universe). Detection per driver:

- **Imagick path**: `Imagick::queryFormats()`, cached request-scope via `imagickQueryFormatsCached()` (single instance per request, mirrors `media_negotiator/lib/Helper.php:216-228`).
- **GD path**: `function_exists('imageavif' / 'imagewebp')` plus `gd_info()['AVIF Support']` / `['WebP Support']`.
- **Baseline (jpg/jpeg/png/gif)**: unconditionally true — short-circuits before either driver branch. Any sane host ships these via GD; an Imagick build that somehow lacks them would also break half of REDAXO.

`renderableFormats()` filters `Config::formats()` through `canServerEncode()`. Three consumers read it: `View\PictureRenderer`, `Pipeline\Preloader`, `Builder\ImageBuilder::resolveDefaultFormat`.

**Why we don't probe-encode anymore.** 1.0.4 added a 1×1 pixel encode probe on top of `queryFormats()` to defend against the *theoretical* case of a registered-but-broken codec library. In practice that case never materialised — the AVIF failures originally blamed on it traced to directory permissions and umask handling (see "Cache filesystem permissions" above), not `queryFormats()` over-reporting. The probe itself produces real false negatives on libheif builds that reject sub-minimum dimensions: an Imagick-loaded server with working AVIF would have its `<picture>` output silently downgraded to WebP. Trust the metadata APIs; if a future encoder turns out to genuinely over-report, add a `try { (new Imagick())->setImageFormat($fmt)->getImageBlob(...) }` guard with a *real-sized* fixture image (e.g. 64×64), not 1×1.

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
gh release create X.Y.Z --title "X.Y.Z" --notes "<release notes>"
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
- IPv6 support in `SsrfGuard`
- shared default filters for all art-direction variants
- subtitle support for videos
- support for vidstack or similar custom video players (requires being able to emit attributes such as `id`, `data-poster` instead of native `poster`, and possibly emitting a non-standard wrapper instead of `<video>`)
