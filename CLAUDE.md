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
- Backend settings page under **AddOns → MASSIF Media → Einstellungen**.
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
├── BE/SettingsPage.php                    # backend form
├── Config.php                             # rex_config wrapper
├── Enum/{Loading,Decoding,FetchPriority}.php
└── Exception/ImageNotFoundException.php
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

## Reference: Statamic addon

The pipeline structure mirrors `~/Repositories/statamic/image` (the user's Statamic responsive-images addon). Key patterns ported: `ImageResolver`/`MetadataReader`/`Placeholder`/`SrcsetBuilder`/`UrlBuilder`/`PictureRenderer`/`PassthroughRenderer` split, Glide `setCachePathCallable`, ColorProfile manipulator.

`_legacy_reference/` is the original `Ynamite\Massif\Media` source from `redaxo-massif`. Kept until the new addon is verified in a live install, then deleted.

## Common operations

- **Add a new public-API method**: add to `lib/Image.php` (or `lib/Video.php`) and the corresponding `lib/Builder/*Builder.php`.
- **Tweak default config**: `lib/Config.php` `DEFAULTS` map. Don't forget the settings page form fields if user-editable.
- **Add a Glide manipulator**: add a class in `lib/Glide/`, register in `Glide/Server.php` after the `setCachePathCallable` line.
- **Add a new extension-point hook**: register in `boot.php`.

## Out of scope (v2 candidates)

- Art direction (multiple `<source media="...">` per breakpoint).
- Image warming (pre-generation of all breakpoints).
- External URL sources (Glide-fetch from arbitrary URLs).
- Visual focal-point picker UI in the backend.
- PHPUnit test suite.
