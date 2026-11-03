# lib/Glide — encoder and watermark gotchas

Loaded when working under `lib/Glide/`. The root `CLAUDE.md` keeps the one-line rules; this file holds the rationale.

## AVIF encoding override

`Glide\SafeAvifEncoder` extends `League\Glide\Api\Encoder` and is wired in via `$api->setEncoder(...)` in both `Server::create()` and `Server::createForExternal()`. Its job is to route the AVIF encode through GD's `imageavif()` instead of Imagick's libheif-backed AVIF encoder, on the hosts where both are present.

Why: at least one Imagick build seen in the wild (Plesk-shipped Imagick with libheif on shared hosting) produces 0-byte output via *every* Imagick AVIF code path — intervention/image v3's specialised `AvifEncoder` (`setCompression(COMPRESSION_ZIP)` + doubled property setters + `getImagesBlob`) AND the minimal pattern `media_negotiator/lib/Helper.php::imagickConvert()` documents (`setImageFormat → setImageCompressionQuality → getImageBlob`). 1.0.5's first attempt at this override used the minimal pattern directly and still produced 0 bytes on those servers. Reading `media_negotiator/lib/rex_effect_negotiator.php` more carefully showed that *its* working AVIF path on the same servers ends in `imagecreatefromstring($blob)` — i.e. the Imagick-encoded AVIF blob is just transport, the FINAL serve goes through GD's `imageavif()` via REDAXO's media pipeline. So the actually-working encoder is GD, not Imagick.

`SafeAvifEncoder::run()` mirrors that:

1. Format is non-AVIF → `parent::run()`.
2. `imageavif()` not available → `parent::run()` (GD AVIF support is required for the override; if missing, fall back to whatever intervention/image produces).
3. Active driver is GD already → `parent::run()` (intervention/image's GD `AvifEncoder` already uses `imageavif`).
4. Imagick driver + GD has `imageavif`: clone the live Imagick instance, render to PNG (lossless intermediate), decode the PNG via `imagecreatefromstring()`, encode AVIF via `imageavif($gd, null, $q)`.

The PNG round-trip is lossless and adds one extra encode/decode pair. Worth the cost: GD's `imageavif()` is the only path consistently producing valid AVIF on the broken-libheif Imagick builds, and on healthy-Imagick hosts the visual quality of GD's AVIF encoder is comparable.

If you ever update `intervention/image` and confirm against the Plesk reference build that their Imagick AvifEncoder produces non-empty output there, this override can come out. Symptom of regression: AVIF cache files at exactly 0 bytes, served as 200 OK with empty body, broken images on AVIF-capable browsers only.

## Watermark manipulator (`Glide\Watermark`)

`Glide\Watermark` extends Glide's stock `League\Glide\Manipulators\Watermark` and replaces it **in-place** in the manipulator array in both `Server::create()` and `Server::createForExternal()`, via `Server::configureManipulators()`. The swap uses a `::class ===` match (not `instanceof`) so only Glide's stock instance is swapped, never an already-installed subclass on a re-configure. The watermarks-filesystem routing layer (`Endpoint::translateMark`, `Pipeline\WatermarkResolver`) is untouched — this is purely a pixel-quality swap, the URL contract is unchanged.

- **Why custom:** Glide's stock manipulator delegates the mark resize to `intervention/image` v3, whose Imagick `ResizeModifier::apply()` calls `Imagick::scaleImage()` — a box-style scaler with **no filter argument**, which produces blurry/aliased edges on sharp marks (logos, text). Our `run()` does the resize itself with `Imagick::resizeImage(..., Imagick::FILTER_LANCZOS, 1, false)`, normalizes the mark to sRGB before any pixel math, applies opacity via `evaluateImage(EVALUATE_MULTIPLY, alpha/100, CHANNEL_ALPHA)`, and composites with `COMPOSITE_OVER`. Imagick-only; on GD installs it falls back to `parent::run()`. Fail-open: a broken/missing mark logs via `rex_logger` and renders the image without the watermark, never 500s.

- **`getApiParams()` override is load-bearing, AND you MUST re-run `Api::setApiParams()` after the swap — both, or `marks` silently vanishes.** There are *two* param filters between `makeImage` and `run()`, both keyed on `getApiParams()`:
  1. `BaseManipulator::setParams()` filters the param array to keys in the *manipulator's own* `getApiParams()`. Stock omits `marks`, so we override `getApiParams()` to add it.
  2. `Server::makeImage()` → `Server::getAllParams()` filters incoming params to keys in the *Api's aggregated* `getApiParams()` — the union of every manipulator's params, which `Api` computes **once in its constructor** (`setApiParams()`) and caches. `setManipulators()` does **not** refresh that cache, and our in-place swap happens *after* construction. So `configureManipulators()` calls `$api->setApiParams()` after `setManipulators()` to rebuild the union from the swapped list. Without this call, `getAllParams()` strips `marks` before `makeImage` ever runs — relative sizing stays a silent no-op despite the override being correct. **Symptom of regression:** `marks="0.25"` has zero effect while `markw="25w"` still works. The unit test (which calls `setParams` directly) cannot catch this; the integration test (`WatermarkPipelineTest::testRelativeMarksControlsWatermarkSize`) can — it goes through the real `makeImage` path.

- **`markfit` reads the raw param, not the inherited `getFit()`.** `getFit()`'s whitelist is Glide's crop vocabulary (`contain`/`max`/`stretch`/`crop*`) and returns `null` for `cover`, which would degrade cover to contain. `computeMarkSize()` reads `getParam('markfit')` directly and treats `cover` **and** any `crop*` token as fill (max-scale), `stretch` as exact w×h, everything else (incl. default `contain`) as fit-inside. Only matters when both `markw` and `markh` are set.

- **`marks` is also in `FilterParams::FRIENDLY_TO_GLIDE` + `RANGES`** so it reaches `activeFilters` and the cache-key hash. This matters now that `marks` is functional: two different `marks` values must hash to different cache paths, or they'd collide on disk (they didn't before, because `marks` did nothing).
