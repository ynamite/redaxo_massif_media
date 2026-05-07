<?php

declare(strict_types=1);

namespace Ynamite\Media\Glide;

use Imagick;
use Intervention\Image\EncodedImage;
use Intervention\Image\Interfaces\EncodedImageInterface;
use Intervention\Image\Interfaces\ImageInterface;
use League\Glide\Api\Encoder as GlideEncoder;

/**
 * Glide encoder override that routes AVIF through a minimal Imagick call
 * pattern. Everything else falls through to Glide's default behaviour.
 *
 * Why we need this: intervention/image v3's specialised AvifEncoder
 * (`vendor/intervention/image/src/Drivers/Imagick/Encoders/AvifEncoder.php`)
 * uses a multi-property setup before requesting the blob —
 *
 *   $imagick->setFormat('AVIF');
 *   $imagick->setImageFormat('AVIF');
 *   $imagick->setCompression(Imagick::COMPRESSION_ZIP);     // suspect
 *   $imagick->setImageCompression(Imagick::COMPRESSION_ZIP);
 *   $imagick->setCompressionQuality($q);
 *   $imagick->setImageCompressionQuality($q);
 *   return $imagick->getImagesBlob();                        // suspect
 *
 * On at least one Imagick build seen in the wild (Plesk-shipped Imagick
 * with libheif on shared hosting) this combination produces an EMPTY blob
 * — the AVIF cache file ends up 0 bytes, the request 200s with no body,
 * the browser shows a broken image. The same Imagick build encodes AVIF
 * correctly via the minimal pattern in `media_negotiator/lib/Helper.php`
 * `imagickConvert()` —
 *
 *   $imagick->setImageFormat('avif');
 *   $imagick->setImageCompressionQuality($q);
 *   return $imagick->getImageBlob();
 *
 * Suspected cause: `setCompression(COMPRESSION_ZIP)` is meaningless for
 * AVIF (the format is AV1-compressed internally; ZIP doesn't apply) but
 * still gets interpreted by some libheif builds and trips the encoder.
 * `getImagesBlob` returns the multi-image stack, which on these builds
 * also doesn't survive the AVIF encode round-trip cleanly.
 *
 * Scope: AVIF + Imagick driver only. WebP works through Glide's default
 * path on the affected servers, and the GD driver delegates to PHP's
 * `imageavif()` without the property dance — only AVIF + Imagick needs
 * this override.
 */
final class SafeAvifEncoder extends GlideEncoder
{
    public function run(ImageInterface $image): EncodedImageInterface
    {
        if (strtolower($this->getFormat($image)) !== 'avif') {
            return parent::run($image);
        }

        $native = $image->core()->native();
        if (!($native instanceof Imagick)) {
            return parent::run($image);
        }

        $native->setImageFormat('avif');
        $native->setImageCompressionQuality($this->getQuality());

        return new EncodedImage($native->getImageBlob(), 'image/avif');
    }
}
