<?php

declare(strict_types=1);

namespace Ynamite\Media\Glide;

use Imagick;
use Intervention\Image\EncodedImage;
use Intervention\Image\Interfaces\EncodedImageInterface;
use Intervention\Image\Interfaces\ImageInterface;
use League\Glide\Api\Encoder as GlideEncoder;

/**
 * Glide encoder override that routes AVIF through GD's `imageavif()` when
 * available, sidestepping Imagick's libheif AVIF encoder. Everything else
 * falls through to Glide's default behaviour.
 *
 * Why we need this: 1.0.5 tried calling Imagick's AVIF encoder directly
 * with the minimal property pattern that `media_negotiator/lib/Helper.php`
 * uses (`setImageFormat → setImageCompressionQuality → getImageBlob`). That
 * pattern still produced 0-byte output on the Plesk-shipped Imagick build
 * we're targeting (vincafilm.ch). Reading
 * `media_negotiator/lib/rex_effect_negotiator.php` more carefully showed
 * that the actually-working AVIF path on those servers ends in
 * `imagecreatefromstring($blob)` — i.e. the Imagick-encoded AVIF gets
 * decoded back into a GD image and the FINAL serve goes through GD's
 * `imageavif()` via REDAXO's media pipeline. So while their `imagickConvert`
 * helper looks like it encodes AVIF via Imagick, the resulting blob is
 * just a transport — GD does the actual encode that ships to the browser.
 *
 * Replicate that flow more directly: take the manipulated Imagick state
 * (after Glide's resize / crop / watermark / our ColorProfile + StripMetadata),
 * render it to a lossless PNG, decode the PNG via GD, then encode AVIF via
 * `imageavif()`. On hosts with both Imagick and GD AVIF (the failing
 * vincafilm shape) this produces working AVIF; on Imagick-with-working-AVIF
 * hosts the GD detour is a wash visually (PNG intermediate is lossless)
 * with one extra round-trip we can live with for the safety it buys.
 *
 * Scope guards:
 *   - non-AVIF format: `parent::run()` — no change to WebP/JPG/PNG paths.
 *   - GD driver in use (no Imagick loaded): `parent::run()` — Glide already
 *     ends up calling `imageavif()` via intervention/image's GD encoder.
 *   - `imageavif()` not available: `parent::run()` — falls back to
 *     intervention's specialised Imagick encoder. May still produce 0 bytes
 *     on the broken-libheif hosts, but those hosts are *also* missing GD
 *     AVIF support so there's no path that works there short of disabling
 *     AVIF entirely (`Config::canServerEncode` already gates that case).
 *   - PNG intermediate or GD decode failure: `parent::run()` — fail-soft,
 *     don't crash the request.
 *
 * Speed: the PNG intermediate is written uncompressed (zlib level 0) and
 * `imageavif()` runs at speed 8. Measured on the target shape (Plesk, PHP
 * 8.5.12, ImageMagick 7.1.2-35, GD 2.3.3, four sources at 2048 px, q50): the
 * PNG encode took 510–2515 ms at Imagick's default level vs 171–391 ms at
 * level 0 — as much as the AVIF encode itself; AVIF took 564–960 ms at
 * libavif's default speed 6 vs 318–520 ms at speed 8, with files −1 % to
 * +7.5 % and no measurable quality difference against the lossless reference.
 * Speed 10 produced the same bytes as 8 there. The level-0 blob is about raw
 * size (~8 MB at 2048 px, ~28 MB at 3840×2400), so it is freed as soon as GD
 * has decoded it.
 */
final class SafeAvifEncoder extends GlideEncoder
{
    /** libavif speed for `imageavif()`: 0 slowest … 10 fastest, default 6. */
    private const AVIF_SPEED = 8;

    public function run(ImageInterface $image): EncodedImageInterface
    {
        if (strtolower($this->getFormat($image)) !== 'avif') {
            return parent::run($image);
        }

        // GD's imageavif is the only path consistently producing valid AVIF
        // on the Plesk-shipped Imagick + libheif builds we're targeting. If
        // GD doesn't have it, there's no override to apply.
        if (!function_exists('imageavif')) {
            return parent::run($image);
        }

        $native = $image->core()->native();
        if (!($native instanceof Imagick)) {
            // GD driver path — intervention/image's GD AvifEncoder already
            // uses imageavif. No reason to interpose.
            return parent::run($image);
        }

        try {
            // Clone before mutating format — `$native` is the live Imagick
            // instance the rest of intervention/image is holding onto, and
            // setImageFormat persists across calls.
            $clone = clone $native;
            $clone->setImageFormat('png');
            // Lossless transport only — don't pay for zlib.
            $clone->setOption('png:compression-level', '0');
            $pngBlob = $clone->getImageBlob();
            $clone->clear();
            $clone->destroy();
        } catch (\Throwable) {
            return parent::run($image);
        }

        $gd = @imagecreatefromstring($pngBlob);
        unset($pngBlob);
        if ($gd === false) {
            return parent::run($image);
        }
        // Imagick writes few-colour images as PNG8 and GD decodes those (and
        // grayscale PNGs) as palette images, which imageavif() rejects
        // ("avif doesn't support palette images").
        imagepalettetotruecolor($gd);
        // Keep the alpha channel in the output. libgd's AVIF writer is not
        // known to drop it without this flag, but it costs nothing and is
        // the documented way to preserve alpha in GD output.
        imagesavealpha($gd, true);

        ob_start();
        imageavif($gd, null, $this->getQuality(), self::AVIF_SPEED);
        $avif = (string) ob_get_clean();
        imagedestroy($gd);

        if ($avif === '') {
            // GD also produced nothing — give up cleanly so the parent
            // encoder gets a chance (probably also empty, but at least the
            // shape matches what 1.0.4 produced before this override).
            return parent::run($image);
        }

        return new EncodedImage($avif, 'image/avif');
    }
}
