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
 */
final class SafeAvifEncoder extends GlideEncoder
{
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
            $pngBlob = $clone->getImageBlob();
            $clone->clear();
            $clone->destroy();
        } catch (\Throwable) {
            return parent::run($image);
        }

        $gd = @imagecreatefromstring($pngBlob);
        if ($gd === false) {
            return parent::run($image);
        }

        ob_start();
        imageavif($gd, null, $this->getQuality());
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
