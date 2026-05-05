<?php

declare(strict_types=1);

namespace Ynamite\Media\Source;

/**
 * A resolvable source for a single image. Two implementations:
 *
 *   - {@see MediapoolSource} — REDAXO mediapool file, keyed by relative
 *     filename, busted by mtime.
 *   - {@see ExternalSource}  — arbitrary HTTPS URL, keyed by '_external/<hash>',
 *     busted by fetchedAt timestamp.
 *
 * The interface is the seam between the polymorphic source-resolution layer
 * (lib/Source/) and the rest of the pipeline (MetadataReader, UrlBuilder,
 * Placeholder, DominantColor, Endpoint). Single source of truth for:
 *   - cache-bucket layout ({@see SourceInterface::key()})
 *   - Glide source-fs reads ({@see SourceInterface::absolutePath()})
 *   - browser-cache busting ({@see SourceInterface::cacheBust()})
 */
interface SourceInterface
{
    /**
     * Cache-bucket identifier — the directory portion of every variant's
     * cache path. Stable across renders for the same logical source.
     *
     * Mediapool: the relative filename (e.g. `'subdir/hero.jpg'`).
     * External:  `'_external/<hash>'` (the underscore prefix avoids collision
     *            with mediapool subdirectories — REDAXO filenames cannot
     *            start with `_` by convention; see also `_meta/`, `_lqip/`,
     *            `_color/`).
     */
    public function key(): string;

    /**
     * Absolute filesystem path where the raw source bytes live. Glide and
     * Imagick read from here.
     *
     * Mediapool: `rex_path::media($filename)`.
     * External:  `cache/_external/<hash>/_origin.bin`.
     */
    public function absolutePath(): string;

    /**
     * Versioning token surfaced as `&v={cacheBust}` on emitted URLs. Changing
     * this value flips the URL — any source change invalidates the browser
     * cache without forcing the on-disk cache path to move.
     *
     * Mediapool: `(string) $mtime`.
     * External:  `(string) $fetchedAt`.
     */
    public function cacheBust(): string;

    /**
     * `true` for {@see ExternalSource}, `false` for {@see MediapoolSource}.
     * Used by Endpoint dispatch and CacheInvalidator routing.
     */
    public function isExternal(): bool;
}
