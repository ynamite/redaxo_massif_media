# CLAUDE.md

Guidance for Claude Code working in this repository.

## What this is

**MASSIF Media** — a standalone REDAXO 5 addon (`package: massif_media`, PHP namespace `Ynamite\Media\` → `lib/`) for responsive image and video rendering. Greenfield, separated from the original `redaxo-massif` kitchen-sink addon.

Design spec: `/Users/yvestorres/.claude/plans/this-directory-is-a-luminous-candy.md`.

The addon **coexists with `redaxo-massif`**. There's no migration shim — old call sites in legacy projects keep using `Ynamite\Massif\Media\...` from `redaxo-massif`; new code uses `Ynamite\Media\...` from this addon.

## What it does

- Emits modern `<picture>` markup (AVIF/WebP/JPG) with browser-side format negotiation. SVG/GIF passthrough.
- On-demand resizing via `league/glide` (Imagick driver, sRGB normalization manipulator).
- Cache lives at `rex_path::addonAssets('massif_media', 'cache/')` — Apache serves direct on hits, PHP shim runs only on misses.
- HMAC-SHA256 signed URLs prevent disk-fill abuse.
- Blurhash via `kornrunner/blurhash` cached in `_meta/` sidecars.
- Optional CDN override (ImageKit / Cloudinary / Imgix template).
- Tabbed backend settings page under **AddOns → MASSIF Media → Einstellungen** (sub-tabs: Allgemein / Placeholder / CDN / Sicherheit & Cache).
- Documentation tab under **AddOns → MASSIF Media → Dokumentation** that renders `README.md` directly via `subPath:` in `package.yml`.
- `REX_PIC[src="..." alt="..." ...]` placeholder parsed via `OUTPUT_FILTER` for content editors.
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
│   ├── Endpoint.php                       # the /_img/ shim handler
│   └── Signature.php                      # HMAC sign + verify
├── Parser/REXPicParser.php                # REX_PIC[...] substitution
├── Config.php                             # rex_config wrapper + typed accessors
├── Enum/{Loading,Decoding,FetchPriority}.php
└── Exception/ImageNotFoundException.php

pages/
├── index.php                              # parent dispatcher (echoes title, includes current subpage)
├── settings.php                           # settings tab dispatcher (includes current sub-subpage)
├── settings.general.php                   # tab: formats, qualities, breakpoints, default sizes
├── settings.placeholder.php               # tab: LQIP + Blurhash
├── settings.cdn.php                       # tab: CDN config
└── settings.security.php                  # tab: sign-key + cache-clear actions + TTLs
```

`assets/_img/index.php` + `assets/.htaccess` handle the URL → cache-or-PHP routing.

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
