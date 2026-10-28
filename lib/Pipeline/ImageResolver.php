<?php

declare(strict_types=1);

namespace Ynamite\Media\Pipeline;

use rex_media;
use Ynamite\Media\Exception\ImageNotFoundException;
use Ynamite\Media\Source\ExternalSourceFactory;
use Ynamite\Media\Source\MediapoolSourceFactory;
use Ynamite\Media\Source\RemoteMedia;
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
        if (is_string($src) && self::looksLikeUrl($src)) {
            return $this->metadataReader->read($this->externalFactory->resolve($src));
        }

        try {
            $source = $this->mediapoolFactory->resolve($src);
        } catch (ImageNotFoundException $missing) {
            return $this->resolveRemote($src, $missing);
        }

        return $this->metadataReader->read($source);
    }

    /**
     * Local-dev fallback: mediapool file missing on disk → fetch the live
     * site's `/media/<file>` through the external pipeline (see
     * {@see RemoteMedia}). The rex_media row is synced even when the file
     * isn't, so its focal point is carried over — the external source alone
     * has no mediapool metadata.
     */
    private function resolveRemote(string|rex_media $src, ImageNotFoundException $missing): ResolvedImage
    {
        $filename = $src instanceof rex_media ? $src->getFileName() : $src;
        $url = RemoteMedia::url($filename);
        if ($url === null) {
            throw $missing;
        }

        try {
            // Host comes from config.yml, not editor input → skip the allowlist; SSRF guard stays on.
            $source = $this->externalFactory->resolve($url, bypassAllowlist: true);
        } catch (ImageNotFoundException $e) {
            throw new ImageNotFoundException($missing->getMessage() . ' (remote fallback failed: ' . $e->getMessage() . ')', 0, $e);
        }

        $image = $this->metadataReader->read($source);
        $media = $src instanceof rex_media ? $src : rex_media::get($filename);
        $raw = $media?->getValue('med_focuspoint');
        $focal = is_string($raw) ? $this->metadataReader->normalizeFocal($raw) : null;

        return $focal === null ? $image : $image->withFocal($focal);
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
