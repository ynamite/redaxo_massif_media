<?php

declare(strict_types=1);

namespace Ynamite\Media\Glide;

use Imagick;
use ImagickException;
use Intervention\Image\Interfaces\ImageInterface;
use League\Glide\Manipulators\BaseManipulator;
use rex_logger;
use Throwable;

/**
 * Glide manipulator that strips all EXIF / XMP / IPTC / ICC profile / comment
 * data from the encoded output. Runs unconditionally for every variant.
 *
 * Why for every variant, not just LQIP:
 *   - Bandwidth: iPhone JPEGs carry 20+ KB of EXIF (face-detection JSON,
 *     depth maps, maker notes), a Display P3 ICC profile, and an XMP packet
 *     with face regions. Multiplied by 3 formats × 5–10 widths per asset,
 *     the savings are real.
 *   - Privacy: GPS coords, photographer credit, face-detection bounding
 *     boxes — none of that should silently ship to public web visitors.
 *   - Correctness: the ColorProfile manipulator (above us in the chain)
 *     normalizes pixels to sRGB via transformImageColorspace() but doesn't
 *     touch the embedded ICC profile. So a Display P3 source ended up with
 *     sRGB pixels claiming a P3 profile — color-managed browsers misrender
 *     that. Stripping the now-stale profile fixes it; browser default is
 *     sRGB, which matches the pixels.
 *
 * If a future ColorProfile rewrite does proper LCMS conversion via
 * profileImage() with a bundled sRGB profile, this manipulator can stay
 * exactly as-is — stripping after that conversion is still correct (we'd
 * then re-attach the sRGB profile in ColorProfile itself if we want
 * explicit color management).
 *
 * Imagick-only. The GD branch encodes minimal metadata anyway, so the no-op
 * is acceptable.
 */
final class StripMetadata extends BaseManipulator
{
    public function getApiParams(): array
    {
        return [];
    }

    public function run(ImageInterface $image): ImageInterface
    {
        $core = $image->core()->native();

        if ($core instanceof Imagick) {
            try {
                $core->stripImage();
            } catch (ImagickException | Throwable $e) {
                rex_logger::logException($e);
            }
        }

        return $image;
    }
}
