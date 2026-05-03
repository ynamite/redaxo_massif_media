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
 * data from the encoded output. Gated by Server::$activeStripMetadata so it
 * only fires for the LQIP path — full-resolution variants keep their profiles
 * intact for color-managed displays.
 *
 * iPhone JPEGs ship 20+ KB of EXIF (face-detection JSON, depth maps, maker
 * notes), a Display P3 ICC profile, and an XMP packet with face regions. None
 * of that survives base64-inlining usefully — strip before encode, save the
 * inline LQIP from being 10× larger than the actual pixel data.
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
        if (!Server::$activeStripMetadata) {
            return $image;
        }

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
