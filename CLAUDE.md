# CLAUDE.md

Guidance for Claude Code working in this repository.

## What this is

**MASSIF Media** — a standalone REDAXO 5 addon (`package: massif_media`, PHP namespace `Ynamite\Media\` → `lib/`) for responsive image and video rendering. Greenfield, separated from the original `redaxo-massif` kitchen-sink addon.

Design spec: `/Users/yvestorres/.claude/plans/this-directory-is-a-luminous-candy.md`.

The addon **coexists with `redaxo-massif`**. There's no migration shim — old call sites in legacy projects keep using `Ynamite\Massif\Media\...` from `redaxo-massif`; new code uses `Ynamite\Media\...` from this addon.

## What it does

- Emits modern `<picture>` markup (AVIF/WebP/JPG) with browser-side format negotiation. SVG/GIF passthrough.
- On-demand resizing via `league/glide` (Imagick driver, sRGB normalization manipulator).
- Cache lives at `rex_path::addonAssets('massif_media', 'cache/')`. Cache misses are served by a `PACKAGES_INCLUDED` (EARLY) hook in REDAXO's frontend — no addon-specific webserver config required. Optional `.htaccess` / nginx snippet serve cache hits directly for the fastpath.
- HMAC-SHA256 signed URLs prevent disk-fill abuse.
- Blurhash via `kornrunner/blurhash` cached in `_meta/` sidecars.
- Optional CDN override (ImageKit / Cloudinary / Imgix template).
- Tabbed backend settings page under **AddOns → MASSIF Media → Einstellungen** (sub-tabs: Allgemein / Placeholder / CDN / Sicherheit & Cache).
- Documentation tab under **AddOns → MASSIF Media → Dokumentation** that renders `README.md` directly via `subPath:` in `package.yml`.
- `REX_PIC[src="..." alt="..." ...]` placeholder via native `rex_var` for content editors. Substitution happens at article-cache-build time, not on every render.
- Preload via `<link rel="preload">` injected into `<head>` via `OUTPUT_FILTER`.
- Focal-point support via the optional `focuspoint` addon's `med_focuspoint` field.

## Architecture

```
lib/
├── Image.php, Pic.php, Video.php          # public API: static one-liners + ::for() builders
├── Builder/{Image,Video}Builder.php       # fluent builders
├── Pipeline/                              # single-purpose units, composable
│   ├── ImageResolver.php                  # rex_media | filename → ResolvedImage
│   ├── MetadataReader.php                 # intrinsic dims + blurhash + focal, cached in meta.json
│   ├── ResolvedImage.php                  # readonly value object
│   ├── SrcsetBuilder.php                  # next/image dual-pool widths
│   ├── UrlBuilder.php                     # signed Glide URL or CDN URL
│   ├── Placeholder.php                    # 32×32 base64 LQIP via Glide
│   └── Preloader.php                      # static queue drained by OUTPUT_FILTER
├── View/{Picture,Passthrough}Renderer.php # full HTML emission
├── Glide/                                 # league/glide integration
│   ├── Server.php                         # factory, cache path callable
│   ├── ColorProfile.php                   # custom manipulator (sRGB)
│   ├── Endpoint.php                       # cache-URL handler (HMAC verify + Glide makeImage + send)
│   ├── RequestHandler.php                 # PACKAGES_INCLUDED hook → Endpoint::handle for self-contained routing
│   └── Signature.php                      # HMAC sign + verify
├── Var/RexPic.php                         # rex_var subclass — REX_PIC[...] substitution at article-cache-build time
├── Config.php                             # rex_config wrapper + typed accessors
├── Enum/{Loading,Decoding,FetchPriority,Fit}.php
└── Exception/ImageNotFoundException.php

pages/
├── index.php                              # parent dispatcher (echoes title, includes current subpage)
├── settings.php                           # settings tab dispatcher (includes current sub-subpage)
├── settings.general.php                   # tab: formats, qualities, breakpoints, default sizes
├── settings.placeholder.php               # tab: LQIP + Blurhash
├── settings.cdn.php                       # tab: CDN config
└── settings.security.php                  # tab: sign-key + cache-clear actions + TTLs
```

**Cache-URL routing is self-contained**: `lib/Glide/RequestHandler.php` registers a `PACKAGES_INCLUDED` (EARLY) hook that catches `/assets/addons/massif_media/cache/…` URLs in REDAXO's frontend `index.php`, calls `Glide\Endpoint::handle()` and `exit`s. Works on every web server REDAXO itself runs on (Apache, nginx, Herd, Valet) without addon-specific server-config. `assets/.htaccess` is shipped as an optional Apache fastpath that serves cache **hits** directly (skipping the PHP boot); `assets/nginx.conf.example` is the equivalent for standalone nginx (per-site `server` block with own `root`). Both are pure performance optimization — the addon works without them.

**Cache-path key has two shapes** depending on whether the request is for a cropped variant: legacy `{fmt}-{w}-{q}/{src}.{ext}` for proportional resize (the only shape before cropping support landed, preserved bit-identically for no-crop URLs), or extended `{fmt}-{w}-{h}-{fitToken}-{q}/{src}.{ext}` for cropped variants. `fitToken` is `cover-{focalX}-{focalY}` / `contain` / `stretch`. `Endpoint::parseCachePath` accepts both shapes; `Endpoint::handle` translates the URL-side `cover-X-Y` token to Glide's `crop-X-Y` before calling `makeImage`, and `Server::cachePath` normalizes the reverse direction (`crop-X-Y` → `cover-X-Y`) so both call paths produce the same on-disk cache path — that's load-bearing for the static-direct fastpath on cache hits.

**Common operation: add the cache-URL routing to a new web-server type** — don't add web-server config files. Trust the `PACKAGES_INCLUDED` hook. Only add a `.htaccess` / nginx-snippet if there's a measurable cache-hit fastpath win on that environment.

## Conventions

- **PHP 8.2+** baseline. Uses `readonly` value objects, enums, named args.
- **PSR-4** via `composer.json`. Run `composer dump-autoload` after adding new files.
- **`vendor/` is committed** so REDAXO Connect ZIP installs work without `composer install`.
- **No tests**. Verification is manual — install in a real REDAXO at `~/Herd/primobau/src` (or similar).
- **German for user-facing strings** (lang file, README, settings page legends, log messages).
- **English for code identifiers** (class names, method names, vars).
- **Defaults shipped**: most installs don't need to touch the settings page.
- **Settings pages follow the viterex pattern** (see `~/Repositories/viterex/viterex-addon/pages/settings.php`): each tab is a self-contained PHP page that builds a `rex_config_form`, wraps it in a `rex_fragment('core/page/section.php')`, and echoes — no shared SettingsPage class.
- **Always keep `README.md`, `CHANGELOG.md`, and this `CLAUDE.md` in sync** with code/convention changes. Each as its own commit. (See feedback memory.)

## REDAXO API gotchas (collected the hard way)

- **`pages/index.php` is required** when the addon declares `subpages:` in `package.yml`. Without it, REDAXO throws "page path 'pages/index.php' neither exists as standalone path nor as package subpath" on the parent route. Pattern: echo title + `rex_be_controller::includeCurrentPageSubPath()`.
- **`pages/settings.php` for nested subpages** does the same dispatch — when settings has its own subpages (Allgemein/Placeholder/…), `pages/settings.php` calls `includeCurrentPageSubPath()` and the sub-tab files are `pages/settings.{name}.php` (dot-separated, phpmailer convention).
- **`subPath: README.md` in `package.yml` subpages** renders a Markdown file as the page body — no PHP page file needed. Used for the Dokumentation tab.
- **`rex_request::isPost()` does not exist.** Use the global function `rex_request_method() === 'post'` instead. Same applies to other request method checks.
- **`rex_config_form::factory($addon)` auto-handles save/validation/styling** for scalar config values (text, number, checkbox, textarea). For complex shapes (arrays, maps), flatten to scalar storage (CSV strings, separate keys per format) and parse on read in `Config.php` typed accessors. Do not hand-roll `<form>` HTML for settings — use this.
- **`addTextField` auto-injects `class="form-control"`** but `addInputField('number', ...)` does **not**. Always explicitly call `$f->setAttribute('class', 'form-control')` after `setLabel(...)` on number/email/etc. inputs, otherwise they render unstyled next to text fields. **Pair it with an inline width** — `form-control` stretches the input to 100% of the container, which looks absurd for short numeric values. Use `$f->setAttribute('style', 'width: 100px')` for 1–3-digit ranges (quality 1–100, LQIP dimensions); `'width: 140px'` for 5–7-digit ranges (TTL seconds). **Also pair with a placeholder sourced from `Config::DEFAULTS`** — `rex_config_form` renders empty inputs on fresh installs (no saved `rex_config` value yet); a placeholder shows the user what the default would be. Use `$f->setAttribute('placeholder', (string) Config::DEFAULTS[Config::KEY_…])` so the hint stays in sync if a default ever changes.
- **In namespaced files**, REDAXO classes (`rex_url`, `rex_view`, `rex_path`, `rex_dir`, `rex_csrf_token`, `rex_media`, `rex_logger`, etc.) need explicit `use rex_xxx;` imports. Global functions (`rex_post`, `rex_get`, `rex_request_method`, …) do not.
- **`rex_dir::delete($path, $deleteSelf = false)`** purges contents but keeps the directory itself — use this from the `CACHE_DELETED` hook so the cache dir stays on disk for subsequent writes.
- **Glide's `setCachePathCallable()` requires a non-static closure** **and** **must not reference `self::` / `static::` in the body**. Glide internally does `Closure::bind($callable, $this, static::class)` (`vendor/league/glide/src/Server.php:365`) before invoking it. Two consequences: (a) `static fn (…)` closures can't be bound — PHP throws `Warning: Cannot bind an instance to a static closure`. (b) Even on a non-static closure, the bind rescopes `self::` / `static::` to `League\Glide\Server`, so a body like `self::cachePath(…)` resolves at runtime against Glide's class, not yours, and fails with `Call to undefined method League\Glide\Server::cachePath()`. Use either an unaliased class name (`Server::cachePath(…)` — resolved at compile time via the file's namespace) or the FQCN (`\Ynamite\Media\Glide\Server::cachePath(…)`). Same pattern applies to any other Glide callable that may get bound in future versions.
- **Self-contained URL routing via `PACKAGES_INCLUDED` (EARLY)** — for addons that need to handle a custom URL pattern without requiring `.htaccess` / nginx tweaks, register at `rex_extension::register('PACKAGES_INCLUDED', […, 'handle'], rex_extension::EARLY)` and short-circuit in the handler with: `if (rex::isBackend()) return;` (cheap fast-path), then match `parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)`, then `rex_response::cleanOutputBuffers(); session_abort();`, do the work, `exit;`. Pattern lifted from `rex_media_manager::init` / `sendMedia`. The hook fires on every frontend request, so the URI-prefix check must be the first thing — anything else and you'll add overhead to every page render. `EARLY` priority guarantees you run before `yrewrite` / Article-Rendering. URLs that don't match get an early return and never call into your code beyond the cheap prefix check.
- **Glide's `fit=crop-{X}-{Y}` rejects decimal coordinates.** The regex at `vendor/league/glide/src/Manipulators/Size.php:118` is `/^(crop)(-[\d]{1,3}-[\d]{1,3}(?:-[\d]{1,3}(?:\.\d+)?)?)*$/` — only the third token (zoom) accepts a decimal. The first two (X, Y) must be 1-3-digit integers. Our focal-point storage (`MetadataReader::normalizeFocal` → `formatFocal`) uses `%g` format which can emit decimals like `50.5%` for a stored `0.505` value. Always `(int) round((float) $x)` before formatting the Glide token, otherwise Glide's Size manipulator falls through to its default fit (which is `contain`, not `crop`) and the focal point is silently ignored. `PictureRenderer::parseFocalToInts()` does this correctly; copy the same pattern if you ever build another path that emits crop coords.
- **Glide's `fit=contain` does NOT letterbox / pad.** It resizes the image proportionally to fit inside the requested w×h box — one dimension hits the box, the other comes out smaller (so the output isn't actually w×h, contrary to what some Glide docs imply). If you need true letterbox with a colored background, that has to happen on the CSS side or via a separate Glide manipulator stack. The `Fit::CONTAIN` enum value passes straight through to Glide.
- **`rex_var` substitution is article-cache-bound.** Native REX_VARs registered via `rex_var::register('REX_PIC', \Ynamite\Media\Var\RexPic::class)` are resolved when REDAXO builds the Article-Cache (in `replaceObjectVars()` at `addons/structure/plugins/content/lib/article_content_base.php:523`). The output of `getOutput()` is a PHP-code string that gets baked into the cache file and `eval`'d on every render. Side effect: changing `getOutput()` requires a `Cache leeren` for stored slice content to pick up new behavior. Bumping the addon version usually triggers this via REDAXO's normal version-up flow. Document this prominently when migrating from OUTPUT_FILTER to `rex_var`. Args from `key="value"` syntax come pre-parsed via `rex_string::split()` (handles quoting); use `getParsedArg('key')` for string passthrough (already-quoted-as-PHP-literal, supports nested REX_VARs) and `getArg('key')` for raw values you need to preprocess (numerics, booleans, custom parsing).

## Reference: Statamic addon

The pipeline structure mirrors `~/Repositories/statamic/image` (the user's Statamic responsive-images addon). Key patterns ported: `ImageResolver`/`MetadataReader`/`Placeholder`/`SrcsetBuilder`/`UrlBuilder`/`PictureRenderer`/`PassthroughRenderer` split, Glide `setCachePathCallable`, ColorProfile manipulator.

`_legacy_reference/` is the original `Ynamite\Massif\Media` source from `redaxo-massif`. Kept until the new addon is verified in a live install, then deleted.

## Common operations

- **Add a new public-API method**: add to `lib/Image.php` (or `lib/Video.php`) and the corresponding `lib/Builder/*Builder.php`.
- **Tweak default config**: `lib/Config.php` `DEFAULTS` map. Don't forget the settings page form fields if user-editable.
- **Add a new settings field**: add the constant + default + typed accessor in `lib/Config.php`, then add the `$form->addXField(...)` call to the appropriate `pages/settings.{tab}.php` file.
- **Add a new settings tab**: declare it under `subpages:` of `settings:` in `package.yml`, create `pages/settings.{name}.php`, follow the same form-build-and-fragment pattern as the existing tabs.
- **Add a Glide manipulator**: add a class in `lib/Glide/`, register in `Glide/Server.php` after the `setCachePathCallable` line.
- **Add a new extension-point hook**: register in `boot.php`.

## Out of scope (v2 candidates)

- Fully automated Favicon generation (basically what realfavicongenerator.net does).
- Art direction (multiple `<source media="...">` per breakpoint).
- Image warming (pre-generation of all breakpoints).
- External URL sources (Glide-fetch from arbitrary URLs).
- Visual focal-point picker UI in the backend.
- PHPUnit test suite.
