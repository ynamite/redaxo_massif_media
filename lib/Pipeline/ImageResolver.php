<?php

declare(strict_types=1);

namespace Ynamite\Media\Pipeline;

use rex_media;
use rex_path;
use Ynamite\Media\Exception\ImageNotFoundException;

final class ImageResolver
{
    public function __construct(private MetadataReader $metadataReader)
    {
    }

    /**
     * Resolve a source reference to a ResolvedImage.
     *
     * Accepts:
     *   - rex_media object
     *   - filename string (must exist in rex_path::media())
     *
     * @throws ImageNotFoundException if the file cannot be read.
     */
    public function resolve(string|rex_media $src): ResolvedImage
    {
        if ($src instanceof rex_media) {
            $filename = $src->getFileName();
            $media = $src;
        } else {
            $filename = $src;
            $media = rex_media::get($filename);
        }

        $absolutePath = rex_path::media($filename);
        if (!is_readable($absolutePath)) {
            throw new ImageNotFoundException(sprintf('Image not found or unreadable: %s', $filename));
        }

        return $this->metadataReader->read($filename, $absolutePath, $media);
    }
}
