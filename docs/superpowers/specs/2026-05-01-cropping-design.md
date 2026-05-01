# Cropping support — `fit` enum, focal-aware crop, ratio/height bugfix

## Context

Today, setting `ratio` or `height` on `Image::picture()` / `Image::for()` / `REX_PIC` only changes the rendered HTML `width`/`height` attributes (layout box reservation). The underlying generated variant from Glide is still proportionally scaled to the requested width with the source's intrinsic aspect — never cropped. The result: the layout box is e.g. 800×800 but the inner image is 800×450. Browser stretches/squishes by default, or letterboxes if the caller adds `object-fit: contain` themselves. This is a silent visual bug, and a real cropping feature is the natural fix.

This spec adds first-class crop support via a `fit` enum, with focal-point-aware center crop as the default when a target shape is set. Closes the gap with Statamic/Glide-style addons and makes the existing `ratio`/`height` arguments behave as users expect.

Non-goals: Glide filter passthrough (sharpen, blur, contrast, etc.) and `sizes` ergonomics presets — both are tracked separately and will get their own specs.

## API

A new `fit` argument joins `Image::picture()`, `Image::for()->fit()`, and `REX_PIC`. Backed by `Ynamite\Media\Enum\Fit` with four values:

| Value | Behavior | Glide param |
|---|---|---|
| `cover` (default when `ratio`/`height` set) | Fill the box, crop overflow, **focal-point-aware** center | `fit=crop-{focalX}-{focalY}` |
| `contain` | Fit fully inside the box, may letterbox | `fit=contain` |
| `stretch` | Squish to box dimensions | `fit=stretch` |
| `none` | No crop — image keeps intrinsic aspect, layout box reserved | (no `h` / no `fit` passed) |

When `ratio`/`height` is unset, `fit` is ignored — there's no target box to fit into. Existing rendering remains bit-identical for callers that don't pass a crop target.

PHP API:
```php
// Implicit cover (default when ratio is set).
Image::picture(src: 'hero.jpg', width: 800, ratio: 1.0);

// Letterbox.
Image::picture(src: 'hero.jpg', width: 800, ratio: 1.0, fit: 'contain');

// Builder.
Image::for('hero.jpg')->width(800)->ratio(1, 1)->fit('contain')->render();

// Disable cropping explicitly even though ratio is set (preserves the buggy
// pre-fix behavior for slices that depended on it).
Image::picture(src: 'hero.jpg', width: 800, ratio: 1.0, fit: 'none');
```

REX_PIC:
```
REX_PIC[src="hero.jpg" width="800" ratio="1:1" fit="cover"]
REX_PIC[src="portrait.jpg" width="600" ratio="3:4" fit="cover" focal="40% 30%"]
```

## Pipeline architecture

The crop semantics flow through five existing pipeline units. No new components.

### `Pipeline/SrcsetBuilder`

Add an optional `?int $effectiveMaxWidth` parameter alongside the existing intrinsic-width cap. When the caller cares about a crop target (`fit=cover` or `fit=contain` with `ratio` set), this is the additional cap that prevents asking Glide to invent pixels:

```php
$effectiveMaxWidth = (int) min($intrinsicWidth, $intrinsicHeight * $ratio);
```

Example: source 5000×4000 with `ratio=9:16` → effectiveMaxWidth = `min(5000, 4000 × 9/16) = 2250`. Pool gets capped at 2250, no upscaling.

For `fit=stretch` and `fit=none`, the existing intrinsic-width cap is enough — pass `null` for `effectiveMaxWidth`.

### `Pipeline/UrlBuilder`

Add `?int $height` and `?string $fitToken` parameters. When both are non-null, the URL gets `&h={height}&fit={fitToken}` appended. `fitToken` is the Glide-syntax string already resolved by `PictureRenderer` (e.g., `crop-50-50` for cover with center focal, `contain`, `stretch`).

Focal percentages must be integer-cast before being formatted into the token — Glide's `fit=crop-{X}-{Y}` regex (`vendor/league/glide/src/Manipulators/Size.php:118`) rejects decimals on the first two coordinates. Use `(int) round($focalX)` / `(int) round($focalY)` in the token construction.

CDN URL template gets two new tokens: `{h}` and `{fit}`. Old templates without these tokens keep working — they emit the same URLs as today.

### `Glide/Server`

`Server::cachePath()` learns the new optional parameters. Cache key shape branches:

- No crop (height null): `{fmt}-{w}-{q}/{src}.{ext}` — **unchanged** for backward compatibility with existing on-disk cache files.
- Crop: `{fmt}-{w}-{h}-{fitToken}-{q}/{src}.{ext}` where `fitToken` is `cover-{focalX}-{focalY}` / `contain` / `stretch`.

### `Glide/Endpoint`

`Endpoint::parseCachePath()` learns to handle both shapes. The parameter group between the first slash now has either three or five hyphen-separated tokens:

- 3 tokens (`fmt-w-q`) → legacy / no-crop.
- 5+ tokens (`fmt-w-h-fitparts...-q`) → crop. The `fitparts` segment may itself contain hyphens for the focal coordinates (`cover-50-50`).

Disambiguate by parsing left-to-right: format, width, then peek — if the next token is numeric and there are more after it, it's `h`; the segment up to the last numeric (which is `q`) is `fitToken`.

### `View/PictureRenderer`

Orchestrates the new pipeline. Steps in `render()`:

1. Resolve effective `Fit` (default `Fit::COVER` when ratio/height set, else `Fit::NONE`).
2. Resolve effective `ratio` from explicit arg, or derive from `height/width` if only height was given.
3. Resolve effective focal point: per-call `focal` > asset focal from `MetadataReader` > `50% 50%`.
4. If cropping (`fit=cover|contain|stretch` and ratio is set): compute `effectiveMaxWidth` for SrcsetBuilder and a `fitToken` for UrlBuilder.
5. Build srcset widths via SrcsetBuilder, with effective cap.
6. For each width in srcset, compute `h = round(w / ratio)` and pass to UrlBuilder.
7. HTML `width`/`height` attrs unchanged from today (`computeIntrinsicAttrs` already does the right thing when ratio is set).

### `Var/RexPic`

Learns the `fit` attribute, passes it through via `getParsedArg('fit')`.

### `Enum/Fit`

New file. Mirrors the existing `Loading` / `Decoding` / `FetchPriority` pattern: enum with `COVER`, `CONTAIN`, `STRETCH`, `NONE` cases backed by their lowercase string names. Public-API entry points accept `Fit|string` (matching the existing pattern); invalid strings raise `\ValueError` from `Fit::from()` at the call site, surfaced before the request hits Glide.

### `Builder/ImageBuilder`

New `fit(Fit|string $fit): self` method. New private `?Fit $fit = null` field. Passed through to `PictureRenderer::render`.

## Focal point handling

Already plumbed through `MetadataReader::normalizeFocal()`, which produces the `"X% Y%"` string format. `UrlBuilder` parses this into two integer percentages for the Glide `crop-{X}-{Y}` token.

Precedence (already implemented elsewhere; just needs to reach UrlBuilder):
1. Per-call `focal` argument (`'40% 30%'` or `'0.4,0.3'` or `'50,30'`).
2. Asset-level focal from the `focuspoint` addon (`med_focuspoint` field, normalized).
3. Center fallback `50% 50%`.

Only matters when `fit=cover`. For `contain` / `stretch` the focal is irrelevant and not part of the cache key.

## Edge cases

- **Source smaller than crop target**. The SrcsetBuilder cap from above filters this — pool tops out at the largest size that fits within the source. Browser may still render at CSS width > intrinsic; in that case it upscales the largest available variant rather than asking Glide to invent pixels.
- **Ratio matches intrinsic exactly**. Detected and treated as no-crop (cache key stays in the legacy 3-token shape, fewer cache files). Saves disk and is bit-identical to today's behavior.
- **`fit=none` with `ratio` set**. Layout box is reserved (HTML `width`/`height` attrs reflect the requested ratio), but the generated image keeps intrinsic aspect. CSS `object-fit` controls visual.
- **`fit=contain` with `ratio` set**. Glide adds padding to fit the image inside the box. Padding color defaults to white in Glide; users who care can set `bg=...` via a Glide-filter pass (separate spec).
- **Missing `ratio`/`height` with explicit `fit`**. `fit` is silently ignored — no target box, nothing to fit into. Document this in the README; don't error.

## Backward compatibility

- Existing slices that pass `ratio` or `height` will start cropping (with `fit=cover` default) after the upgrade. Visually different from today — but today's behavior is the silent bug being fixed. The CHANGELOG entry will flag this as a deliberate visual change and recommend `Cache leeren` to drop old uncropped variants from disk.
- Slices that depended on the old "ratio sets only the layout box, image keeps aspect" semantic can opt back in with `fit="none"`.
- Existing cache files on disk (legacy `{fmt}-{w}-{q}/...` shape) keep working — current URLs that don't request a crop continue to point at the legacy shape. New crop URLs use the extended shape and miss → generate fresh. No corrupted state, no manual migration.
- The optional `.htaccess` and `nginx.conf.example` fastpaths stay valid for both shapes — they match by file extension, not by path-segment count.

## Critical files to modify

- `lib/Enum/Fit.php` — **new**.
- `lib/Image.php` — `picture()` signature gets `Fit|string|null $fit = null`. Null is the public default; `PictureRenderer` resolves it to `Fit::COVER` when a target box is set (`ratio` or `height` non-null) and to a no-op otherwise.
- `lib/Pic.php` — `render()` mirrors the same arg if it exposes the same surface.
- `lib/Builder/ImageBuilder.php` — new `fit()` method, new field, pass through to renderer.
- `lib/Pipeline/UrlBuilder.php` — accept `?int $height` + `?string $fitToken`. CDN template gains `{h}`, `{fit}` tokens.
- `lib/Glide/Server.php` — `cachePath()` accepts `?int $height` + `?string $fitToken`, switches between 3-token and 5+ token cache key shapes.
- `lib/Glide/Endpoint.php` — `parseCachePath()` handles both shapes, passes `h` and `fit` into `Server::makeImage` params when present.
- `lib/Pipeline/SrcsetBuilder.php` — add `?int $effectiveMaxWidth`; existing intrinsic cap remains.
- `lib/View/PictureRenderer.php` — orchestrates `fit` resolution, ratio derivation, focal resolution, per-width height computation.
- `lib/Var/RexPic.php` — passes `fit` via `getParsedArg`.
- `README.md` — add `fit` row to REX_PIC attribute table; update Erzeugtes Markup example to show a cropped variant; explain backward-compat note in the cropping section.
- `CHANGELOG.md` — Added (Fit enum + cropping), Fixed (ratio/height now actually crop), Changed (cache key gains optional segments).
- `CLAUDE.md` — note the cache-key extension under REDAXO API gotchas / Common operations: parsing the new shape and the legacy fallback.

## Reused functions / utilities

- `MetadataReader::normalizeFocal()` — produces the `"X% Y%"` string consumed by UrlBuilder.
- `ResolvedImage::aspectRatio()` — used to detect "ratio matches intrinsic" no-op shortcut.
- `Signature::sign()` / `verify()` — operates over the cache path string regardless of shape; no signature-shape changes needed.
- `Config::defaults()` and the existing settings page — no new settings; `fit` is per-call, not configurable.

## Verification

- **Unit-style smoke test (manual)**: render `Image::picture(src: 'large.jpg', width: 800, ratio: 1.0)` against a 5000×4000 source. Expected: HTML `width="800" height="800"`, srcset entries top out at min(5000, 4000) = 4000, every cache URL contains the new `{fmt}-{w}-{h}-cover-50-50-{q}/...` segment.
- **Focal-point integration**: install `focuspoint` addon, set `med_focuspoint = "30,70"` on a test asset, render with `fit=cover` and no per-call `focal`. Expected: cache URL contains `cover-30-70`. Then render with `focal="60% 20%"` to override. Expected: cache URL contains `cover-60-20`.
- **Letterbox**: `Image::picture(src: 'wide.jpg', width: 800, ratio: 1.0, fit: 'contain')` against a 16:9 source. Expected: variant is 800×800, image is 800×450 padded with white above/below. Cache URL contains `contain` (no focal).
- **No-crop opt-out**: `Image::picture(src: 'wide.jpg', width: 800, ratio: 1.0, fit: 'none')`. Expected: cache URL is the legacy `{fmt}-{w}-{q}/...` shape; HTML `width="800" height="800"`; visual is squished/letterboxed depending on caller's CSS.
- **Backward compat**: existing pre-upgrade cache files at `{fmt}-{w}-{q}/...` paths keep serving without 404. URL change for cropped variants is opt-in via the `fit` arg.
- **REX_PIC parity**: `REX_PIC[src="hero.jpg" width="800" ratio="1:1" fit="cover" focal="40% 30%"]` after `Cache leeren` produces the same markup as the equivalent `Image::picture()` call.
- **End-to-end**: install in `~/Herd/viterex-installer-default`, exercise all four `fit` modes via REX_PIC slices, verify generated cache directory layout matches the spec.
