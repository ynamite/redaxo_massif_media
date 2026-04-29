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
 * Custom Glide manipulator that normalizes the output image's colorspace to sRGB.
 *
 * Inspired by the Statamic responsive_images addon's ColorProfile manipulator.
 * Fixes Display P3 / Adobe RGB photos (typical for iPhone / DSLR captures) that
 * would otherwise appear washed out or wrong-hued when rendered by browsers
 * assuming sRGB.
 *
 * Only effective when the Imagick driver is in use. GD has no equivalent
 * built-in colorspace transform, so this manipulator no-ops there.
 *
 * v1 implementation uses Imagick::transformImageColorspace(). For full ICC
 * profile conversion via LCMS, a future v1.x can ship an sRGB ICC profile
 * and call profileImage().
 */
final class ColorProfile extends BaseManipulator
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
                if ($core->getImageColorspace() !== Imagick::COLORSPACE_SRGB) {
                    $core->transformImageColorspace(Imagick::COLORSPACE_SRGB);
                }
            } catch (ImagickException | Throwable $e) {
                rex_logger::logException($e);
            }
        }

        return $image;
    }
}
