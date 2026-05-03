# Improvement catalog

A living wishlist of refactor / feature / ops opportunities for MASSIF Media. Distinct from `parking-lot.md` — that's small ride-along items deferred from active specs; this is forward-looking ideas with an opinionated rank.

Tags:
- **Recommend** — high ROI, build when capacity allows.
- **Consider** — worth a brainstorm before committing; tradeoffs aren't obvious.
- **Skip** — cataloged so the "no" is defensible. Move out of Skip if context changes.

When an item ships, mark it `✅ shipped <YYYY-MM-DD> <commit-sha-or-PR-ref>` and leave it in place — the catalog doubles as a record of which ideas were looked at and why each was or wasn't done.

---

## Refactors

### A1 — Unit-test the View renderers — **Recommend**, M
- **Where:** `lib/View/PictureRenderer.php` (238 LOC), `lib/View/PassthroughRenderer.php` (54 LOC). No `tests/Unit/View/` directory exists today.
- **Why:** PictureRenderer is the most complex untested file. Effective-ratio resolution, `needsCrop` logic, focal/LQIP style merge, `aria-hidden` rule, fallback-format picking — all silently breakable. Integration tests (`CropPipelineTest`, etc.) catch breakage but slowly and noisily.
- **Bound:** stop at the renderer's `render()` boundary; don't re-test `SrcsetBuilder`, `UrlBuilder`, `Placeholder` which already have unit tests.

### A2 — Extract `PreloadLinkBuilder` so `Preloader` stops re-implementing render logic — **Consider**, S
- **Where:** `lib/Pipeline/Preloader.php:53-103` mirrors `lib/View/PictureRenderer.php:56-119` for ratio derivation, fit-token selection, and srcset assembly.
- **Why:** Verified divergence risk. Today the two paths agree, but any future change to crop/srcset/focal handling has to be made twice. The Preloader path runs late (OUTPUT_FILTER) so silent drift is easy to miss.
- **Likely promotion candidate after the Wave 4 features land** — animated-WebP + dominant-color both touch the same emit code, and duplicating those changes will be the trigger.

### A3 — Delete `_legacy_reference/` + fix CLAUDE.md `parseFocalToInts` drift — **Recommend**, S
- **Where:** `_legacy_reference/` (last touched in initial skeleton commit `840f2e0`); CLAUDE.md references `PictureRenderer::parseFocalToInts()` which **does not exist** — the rounding lives in `FitTokenBuilder::build()`.
- **Why:** Folder is dead weight in the repo and Connect installs. Doc drift is a real trap for future Claude sessions reading CLAUDE.md.

### A4 — Reconcile `Image::picture()` vs `Video::render()` API — **Consider**, S
- **Where:** `lib/Image.php:22` (`picture(...)`), `lib/Video.php:18` (`render(...)`). Different verbs, different argument orders, different `preload` semantics (bool on Image, string on Video).
- **Why:** Friction for anyone using both. Not a bug, just cognitive load.
- **Likely outcome:** document the asymmetry rather than rename — renaming breaks every existing call site for ergonomic-only gain.

### A5 — Centralize HTML-escaping — **Skip** unless escape strategy changes
- Three or four call sites repeat `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` (Preloader uses bare `ENT_QUOTES`). Variants subtly differ. Extracting now is bikeshedding. Revisit if charset handling ever changes.

---

## Features

### B1 — Dominant-color placeholder — ✅ shipped (independent toggle, prepended `background-color`)
- New `lib/Pipeline/DominantColor.php`. Imagick `quantizeImage(1, COLORSPACE_SRGB)` on a scaled-down working copy. Cache at `cache/_color/<prefix>/<hash>.txt`. Two independent toggles (lqip + color); style attr renders color → LQIP image → focal in that order so each layer overlays the previous. Tests cover gates (unit) + real Imagick path (integration).

### B2 — Animated WebP for animated GIFs — ✅ shipped (single-width MVP, picture+gif fallback)
- New `lib/Pipeline/AnimatedWebpEncoder.php` bypasses Glide's single-frame encoder via `Imagick::coalesceImages() + writeImages(adjoin=true)`. Single intrinsic-width variant per source — animated WebP support correlates with WebP support, so no srcset needed. Cache `cache/{src}/animated.webp`. `Endpoint::handle` matches `/animated.webp` suffix and dispatches to the encoder before reaching Glide. `MetadataReader::probeAnimated` adds `is_animated` to the meta sidecar; `ResolvedImage` gets a `readonly bool $isAnimated`. CDN mode skips the wrap (CDN can't run our encoder). Animated PNG / animated WebP-as-source explicitly out of scope for v1; promote when someone asks.

### B3 — Aspect-ratio CSS belt-and-suspenders — **Skip**
- `PictureRenderer` already emits `width=` and `height=` attributes (line 129-130). Modern browsers compute `aspect-ratio` from those automatically for CLS prevention. Adding explicit `style="aspect-ratio: …"` is redundant. Document the existing behavior in README rather than ship duplicate output.

### B4 — Better dev-mode feedback for missing src — **Recommend**, S
- **Where:** `lib/Builder/VideoBuilder.php` (silently returns `''`, no log — verified); `lib/Builder/ImageBuilder.php` (catches and logs).
- **Why:** Today a typo'd `src` produces empty output with no signal. Editors blame "the picture isn't showing" with no console message.
- **Approach:** make Video log missing files (parity with Image); when `rex::isDebug()` is true, render `<!-- massif_media: src not found "<filename>" -->` instead of empty string.

### B5 — JPEG XL, Blurhash, ThumbHash, Schema.org JSON-LD, Open Graph helper, YForm field — **Skip / defer**
- Cataloged as v2 candidates per CLAUDE.md. Imagick JXL support still niche; Blurhash/ThumbHash add client-side JS deps that conflict with the addon's "ship working markup" stance; OG/Schema generation is a CMS-level concern; YForm-field belongs in a sister addon.

---

## Operations & Backend UX

### C1 — Settings-page descriptions / help text — **Recommend**, S
- **Where:** `pages/settings.{general,placeholder,cdn,security}.php`.
- **Why:** First-time users don't know the difference between format list, breakpoint widths, LQIP knobs, or what the sign-key protects. `rex_config_form` supports per-field descriptions — costs nothing.

### C2 — Cache stats panel on Sicherheit & Cache tab — **Consider**, M
- **Where:** `pages/settings.security.php`.
- **Why:** No way today to see how big the cache has grown, when it was last cleared, or how many variants live there. Image-heavy sites care about disk usage.
- **Bound:** read-only stats. No LRU eviction, no per-variant deletion UI — those are real features that need their own design.

### C3 — CLI cache-warming command — **Consider**, M
- **Where:** new `lib/Console/WarmCommand.php` via `rex_console_command`.
- **Why:** Pre-generate cache after a deploy / sign-key rotation so first user doesn't pay generation cost.
- **Tradeoff:** real value only on sites with predictable hot images. For most installs the on-demand generation is fine.

### C4 — "Test signing key" backend button — **Skip**
- HMAC roundtrip is already covered by `tests/Integration/HmacRoundtripTest.php`. Backend button gives pretty output but doesn't catch any class of bug the test suite doesn't already catch.

---

## Verification snapshot (2026-05-03)

The findings above were spot-checked against the source on 2026-05-03:

- ✅ No `tests/Unit/View/` directory (`ls tests/Unit/`)
- ✅ `parseFocalToInts` not defined anywhere (`grep -rn parseFocalToInts lib/`)
- ✅ `Preloader::drain` re-implements `PictureRenderer` srcset/ratio/fit-token logic (`lib/Pipeline/Preloader.php:56-90` vs `lib/View/PictureRenderer.php:56-119`)
- ✅ `_legacy_reference/` last modified in initial skeleton commit `840f2e0`
- ✅ `Image::picture()` vs `Video::render()` use different verbs
- ✅ `PictureRenderer` already emits `width=` and `height=` HTML attributes (line 129-130) — supports B3 → Skip
- ✅ Video error path silent vs Image error path logged

If any of these change, the corresponding catalog item may need re-grading.
