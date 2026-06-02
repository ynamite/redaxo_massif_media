<?php

declare(strict_types=1);

namespace Ynamite\Media\Glide;

use Imagick;
use ImagickException;
use Intervention\Image\Interfaces\ImageInterface;
use League\Flysystem\FilesystemException as FilesystemV2Exception;
use League\Glide\Filesystem\FilesystemException;
use League\Glide\Manipulators\Watermark as GlideWatermark;
use rex_logger;
use Throwable;

/**
 * High-quality watermark manipulator. Replaces Glide's stock
 * {@see \League\Glide\Manipulators\Watermark} in the manipulator chain so we
 * can resize the mark with Imagick's Lanczos filter and composite it with
 * proper alpha math.
 *
 * The stock manipulator delegates resize to Intervention/Image v3's Imagick
 * driver, where {@see \Intervention\Image\Drivers\Imagick\Modifiers\ResizeModifier}
 * calls `Imagick::scaleImage($w, $h)` — a fast pixel-area scaler with no
 * filter argument. That's adequate for solid-colour or low-frequency
 * thumbnails, but produces blurry, ringy edges for marks with text or
 * sharp lines (the typical brand-logo watermark). Doing the resize
 * ourselves with `Imagick::resizeImage(..., FILTER_LANCZOS, 1)` fixes that.
 *
 * Two ancillary improvements:
 *   - We honour `marks` (relative size, `0.0..1.0`) which Glide's stock
 *     manipulator silently ignores (it isn't in `getApiParams()` and
 *     `run()` never reads it). With this manipulator, `marks=0.25`
 *     finally produces a watermark sized to 25% of the source width,
 *     instead of being a no-op.
 *   - We composite with `Imagick::COMPOSITE_OVER` for unambiguous,
 *     alpha-correct blending. Stock goes through Intervention's `place()`
 *     which lands on `compositeImage` with `COMPOSITE_DEFAULT` — driver-
 *     dependent semantics that contributed to soft edges in some setups.
 *
 * Drop-in replacement for Glide's manipulator — same param surface, same
 * URL contract, same filesystem-routing layer
 * ({@see \Ynamite\Media\Pipeline\WatermarkResolver},
 * {@see \Ynamite\Media\Glide\Endpoint::translateMark()}).
 *
 * Imagick-only. On GD installs we fall back to the parent (stock Glide)
 * implementation since GD has no equivalent of `resizeImage(FILTER_LANCZOS)`;
 * the quality fix targets Imagick, which is what every production install
 * uses (the addon also gracefully degrades when Imagick is missing — see
 * {@see Server::create()}).
 */
final class Watermark extends GlideWatermark
{
    public function getApiParams(): array
    {
        // This override is load-bearing, not cosmetic. Glide's
        // BaseManipulator::setParams() filters the incoming param array
        // down to keys present in getApiParams() (array_filter with
        // ARRAY_FILTER_USE_KEY). Glide's stock Watermark omits `marks`, so
        // without adding it here `marks` would be stripped before it ever
        // reached run()/getRelativeSize() — which is exactly why relative
        // sizing is a silent no-op in stock Glide. Adding it makes
        // `marks=0.25` survive into our manipulator and take effect.
        return array_merge(parent::getApiParams(), ['marks']);
    }

    public function run(ImageInterface $image): ImageInterface
    {
        $core = $image->core()->native();
        if (!$core instanceof Imagick) {
            // GD or other driver — fall back to stock behaviour rather than
            // ship a broken render. The whole quality fix targets Imagick.
            return parent::run($image);
        }

        if ($this->watermarks === null) {
            return $image;
        }

        $markPath = (string) $this->getParam('mark');
        if ($markPath === '') {
            return $image;
        }

        if ($this->watermarksPathPrefix !== '') {
            $markPath = $this->watermarksPathPrefix . '/' . $markPath;
        }

        $mark = null;
        try {
            if (!$this->watermarks->fileExists($markPath)) {
                return $image;
            }

            $markBlob = $this->watermarks->read($markPath);

            $mark = new Imagick();
            $mark->readImageBlob($markBlob);

            // Normalize the mark to sRGB before any pixel math. The existing
            // ColorProfile manipulator handles the source after we run, but
            // the mark needs the same treatment up front because we're about
            // to combine pixel values across both images. Skip if already
            // sRGB — transformImageColorspace is cheap-ish but not free.
            if ($mark->getImageColorspace() !== Imagick::COLORSPACE_SRGB) {
                $mark->transformImageColorspace(Imagick::COLORSPACE_SRGB);
            }

            [$targetW, $targetH] = $this->computeMarkSize($image, $mark);

            // High-quality resize. Lanczos is the best-practice default for
            // photographic content; for marks that are pure line-art or
            // text, Mitchell or Catrom can be marginally sharper but more
            // ringy on edges — Lanczos balances both. A pre-rendered mark
            // already at its target size hits a no-op below.
            if ($targetW !== $mark->getImageWidth() || $targetH !== $mark->getImageHeight()) {
                $mark->resizeImage($targetW, $targetH, Imagick::FILTER_LANCZOS, 1, false);
            }

            // Apply opacity. Glide's `markalpha` is 0..100, with 100 meaning
            // fully opaque (visible). Below 100, multiply the alpha channel
            // by `alpha/100`. Stock Glide passes the alpha value to
            // Intervention's `place()` which divides by 100 internally.
            $alpha = (int) ($this->getAlpha() ?? 100);
            if ($alpha < 100 && $alpha >= 0) {
                $mark->evaluateImage(
                    Imagick::EVALUATE_MULTIPLY,
                    max(0.0, $alpha / 100.0),
                    Imagick::CHANNEL_ALPHA,
                );
            }

            [$x, $y] = $this->computePlacement($image, $mark);

            $core->compositeImage($mark, Imagick::COMPOSITE_OVER, $x, $y);
        } catch (FilesystemV2Exception $e) {
            throw new FilesystemException('Could not read the watermark image `' . $markPath . '`.');
        } catch (ImagickException | Throwable $e) {
            // Fail open — same posture as the stock manipulator. A broken
            // mark blob shouldn't 500 the entire request; the source image
            // renders without the watermark, the failure is logged, and
            // the cache file is still produced (just unwatermarked).
            rex_logger::logException($e);
        } finally {
            $mark?->clear();
        }

        return $image;
    }

    /**
     * Resolve target mark dimensions from the params.
     *
     * Order of precedence:
     *   1. explicit `markw` and/or `markh` (pixels or `Nw`/`Nh` percent)
     *   2. relative `marks` (`0.0..1.0`; mark width = source.w × marks)
     *   3. native mark size (no resize)
     *
     * `markfit` controls aspect handling when both `markw` and `markh` are
     * given. We accept the addon's own fit vocabulary (mirrors the main
     * `fit` attribute) plus Glide's `crop*` tokens for back-compat:
     *   - `contain` (default): preserve aspect, fit inside the box
     *   - `cover` / `crop` / `crop-*`: preserve aspect, fill the box (mark may overflow)
     *   - `stretch`: ignore aspect, scale to exact w×h
     *
     * @return array{int, int}
     */
    private function computeMarkSize(ImageInterface $image, Imagick $mark): array
    {
        $markW = max(1, $mark->getImageWidth());
        $markH = max(1, $mark->getImageHeight());

        $markw = $this->getDimension($image, 'markw');
        $markh = $this->getDimension($image, 'markh');

        if ($markw === null && $markh === null) {
            $marks = $this->getRelativeSize();
            if ($marks !== null) {
                $sourceW = max(1, $image->width());
                $targetW = max(1, (int) round($sourceW * $marks));
                $targetH = max(1, (int) round($markH * ($targetW / $markW)));
                return [$targetW, $targetH];
            }
            return [$markW, $markH];
        }

        $boxW = $markw !== null ? max(1, (int) round($markw)) : null;
        $boxH = $markh !== null ? max(1, (int) round($markh)) : null;

        if ($boxW !== null && $boxH === null) {
            $targetW = $boxW;
            $targetH = max(1, (int) round($markH * ($targetW / $markW)));
            return [$targetW, $targetH];
        }
        if ($boxH !== null && $boxW === null) {
            $targetH = $boxH;
            $targetW = max(1, (int) round($markW * ($targetH / $markH)));
            return [$targetW, $targetH];
        }

        // Both dimensions given. Read `markfit` raw rather than via the
        // inherited getFit(), whose whitelist (contain/max/stretch/crop*)
        // never returns `cover` — that would silently degrade cover to
        // contain. We treat `cover` and any `crop*` token as fill.
        $fit = strtolower((string) $this->getParam('markfit'));
        if ($fit === 'stretch') {
            return [(int) $boxW, (int) $boxH];
        }

        $fill = $fit === 'cover' || str_starts_with($fit, 'crop');
        $scale = $fill
            ? max($boxW / $markW, $boxH / $markH)
            : min($boxW / $markW, $boxH / $markH);

        return [
            max(1, (int) round($markW * $scale)),
            max(1, (int) round($markH * $scale)),
        ];
    }

    /**
     * Top-left (x, y) of the mark on the source, derived from `markpos` and
     * `markpad`. Mirrors Glide's nine-position vocabulary plus the four
     * single-direction shorthands (`top` / `bottom` / `left` / `right`,
     * which centre the other axis). The inherited getPosition() validates
     * against that vocabulary and defaults to `bottom-right`.
     *
     * @return array{int, int}
     */
    private function computePlacement(ImageInterface $image, Imagick $mark): array
    {
        $sourceW = $image->width();
        $sourceH = $image->height();
        $markW = $mark->getImageWidth();
        $markH = $mark->getImageHeight();

        $pad = (int) ($this->getDimension($image, 'markpad') ?? 0);

        $position = $this->getPosition();
        $h = 'center';
        $v = 'center';
        foreach (explode('-', $position) as $part) {
            if ($part === 'top' || $part === 'bottom') {
                $v = $part;
            } elseif ($part === 'left' || $part === 'right') {
                $h = $part;
            }
        }

        $x = match ($h) {
            'left' => $pad,
            'right' => $sourceW - $markW - $pad,
            default => intdiv($sourceW - $markW, 2),
        };
        $y = match ($v) {
            'top' => $pad,
            'bottom' => $sourceH - $markH - $pad,
            default => intdiv($sourceH - $markH, 2),
        };

        return [$x, $y];
    }

    /**
     * Read the relative-size param (`marks`, 0.0..1.0). Returns null if
     * absent, non-numeric, or outside the valid range.
     */
    private function getRelativeSize(): ?float
    {
        $marks = $this->getParam('marks');
        if (!is_numeric($marks)) {
            return null;
        }
        $value = (float) $marks;
        if ($value <= 0.0 || $value > 1.0) {
            return null;
        }
        return $value;
    }
}
