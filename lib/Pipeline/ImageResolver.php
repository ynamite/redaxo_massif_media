<?php

declare(strict_types=1);

namespace Ynamite\Media\Pipeline;

use rex_media;
use Ynamite\Media\Exception\ImageNotFoundException;
use Ynamite\Media\Source\ExternalSourceFactory;
use Ynamite\Media\Source\MediapoolSourceFactory;
use Ynamite\Media\Source\SourceInterface;

/**
 * Front door for source resolution. Dispatches to the appropriate factory
 * based on the input shape:
 *
 *   - `string` containing `://` → {@see ExternalSourceFactory} (HTTPS URL)
 *   - `rex_media` or bare-filename string → {@see MediapoolSourceFactory}
 *
 * The resulting {@see SourceInterface} is fed to {@see MetadataReader} which
 * computes / caches intrinsic dims + focal + format and wraps the source in
 * a {@see ResolvedImage}.
 */
final class ImageResolver
{
    private MediapoolSourceFactory $mediapoolFactory;
    private ExternalSourceFactory $externalFactory;

    public function __construct(
        private MetadataReader $metadataReader,
        ?MediapoolSourceFactory $mediapoolFactory = null,
        ?ExternalSourceFactory $externalFactory = null,
    ) {
        $this->mediapoolFactory = $mediapoolFactory ?? new MediapoolSourceFactory();
        $this->externalFactory = $externalFactory ?? new ExternalSourceFactory();
    }

    /**
     * @throws ImageNotFoundException if the source cannot be resolved (mediapool
     *   file unreadable, or external URL fetch failed).
     */
    public function resolve(string|rex_media $src): ResolvedImage
    {
        $source = is_string($src) && self::looksLikeUrl($src)
            ? $this->externalFactory->resolve($src)
            : $this->mediapoolFactory->resolve($src);

        return $this->metadataReader->read($source);
    }

    /**
     * Lightweight URL detector. Anything containing `://` is routed to the
     * external factory; the factory itself enforces the scheme allowlist
     * (http/https only) and the SSRF guard.
     */
    private static function looksLikeUrl(string $src): bool
    {
        return str_contains($src, '://');
    }
}
