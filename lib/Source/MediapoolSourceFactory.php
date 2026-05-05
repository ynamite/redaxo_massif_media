<?php

declare(strict_types=1);

namespace Ynamite\Media\Source;

use rex_media;
use rex_path;
use Ynamite\Media\Exception\ImageNotFoundException;

/**
 * Resolves a `rex_media` or filename string into a {@see MediapoolSource}.
 *
 * Bare-filename callers without a matching `rex_media` row still get a
 * source — the file just has to exist on disk under `rex_path::media()`.
 * Used by `ImageResolver` for the mediapool branch.
 */
final class MediapoolSourceFactory
{
    /**
     * @throws ImageNotFoundException if the file isn't readable on disk.
     */
    public function resolve(string|rex_media $src): MediapoolSource
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

        $mtime = (int) (filemtime($absolutePath) ?: 0);

        return new MediapoolSource(
            filename: $filename,
            absolutePath: $absolutePath,
            mtime: $mtime,
            media: $media,
        );
    }
}
