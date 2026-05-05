<?php

declare(strict_types=1);

namespace Ynamite\Media\Source;

use rex_media;

/**
 * Source backed by a REDAXO mediapool file. Resolved by
 * {@see MediapoolSourceFactory} from a `rex_media` instance or a bare
 * filename string.
 *
 * `$media` is preserved when available so {@see MetadataReader} can read
 * `med_focuspoint` without re-querying. It's nullable because callers may
 * pass a filename string for an asset that exists on disk but has no
 * `rex_media` row (rare; tolerated).
 */
final readonly class MediapoolSource implements SourceInterface
{
    public function __construct(
        public string $filename,
        public string $absolutePath,
        public int $mtime,
        public ?rex_media $media = null,
    ) {
    }

    public function key(): string
    {
        return $this->filename;
    }

    public function absolutePath(): string
    {
        return $this->absolutePath;
    }

    public function cacheBust(): string
    {
        return (string) $this->mtime;
    }

    public function isExternal(): bool
    {
        return false;
    }
}
