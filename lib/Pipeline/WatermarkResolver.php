<?php

declare(strict_types=1);

namespace Ynamite\Media\Pipeline;

use Closure;
use rex_logger;
use rex_path;
use Throwable;
use Ynamite\Media\Config;
use Ynamite\Media\Source\ExternalSource;
use Ynamite\Media\Source\ExternalSourceFactory;
use Ynamite\Media\Source\ExternalManifest;

/**
 * Resolves a user-supplied `mark` filter value to a path readable by Glide's
 * watermarks filesystem (rooted at {@see rex_path::frontend()} — see
 * {@see \Ynamite\Media\Glide\Server::create()}). Three input shapes:
 *
 *   - mediapool filename (`"logo.png"`, `"subdir/logo.png"`) → prefixed with
 *     `media/` so the watermarks FS resolves it under the mediapool root
 *     (`rex_path::media()`, which REDAXO always anchors at
 *     `rex_path::frontend('media/')`).
 *   - frontend-relative URL (`"/assets/addons/foo/img.png"`, typically
 *     emitted by a nested `REX_PIC[..., as='url']`) → leading slash + query
 *     string stripped, treated as a path under `rex_path::frontend()`.
 *     Caveat: the file must already exist on disk when the watermark
 *     renders — Glide-cache URLs only point at a real file once the variant
 *     has been generated.
 *   - HTTPS URL (`"https://example.com/logo.png"`) → routed through
 *     {@see ExternalSourceFactory} for fetch + SSRF + TTL handling, then
 *     translated to the relative path under the addon's external cache
 *     bucket (`assets/addons/massif_media/cache/_external/<hash>/_origin.bin`).
 *
 * All three shapes anchor at `rex_path::frontend()` rather than
 * `rex_path::base()` so installers that offset the public dir (e.g. Viterex
 * with `<base>/public/`) resolve correctly — mediapool and assets are both
 * subdirs of `frontend()` per REDAXO's path provider contract.
 *
 * Returns null on failure (bad URL, SSRF block, fetch error). Caller should
 * drop the `mark` filter — the picture then renders without a watermark
 * rather than 500-ing the page. Failures are logged via `rex_logger`.
 */
final class WatermarkResolver
{
    /**
     * @param null|Closure(string): ExternalSource $externalResolver Closure
     *        seam for tests — passed a URL, returns the resolved source (or
     *        throws). Defaults to {@see ExternalSourceFactory::resolve()},
     *        which carries the SSRF guard, TTL handling, and conditional GET
     *        plumbing. Closure indirection avoids forcing
     *        `ExternalSourceFactory` to be non-final or extracting an
     *        interface for one production caller.
     */
    public function __construct(
        private ?Closure $externalResolver = null,
    ) {
    }

    public function resolve(string $rawMark): ?string
    {
        if (str_contains($rawMark, '://')) {
            return $this->resolveExternal($rawMark);
        }

        // Leading-slash → frontend-relative URL (e.g. nested
        // `REX_PIC[as='url']` produces `/assets/addons/.../foo.webp?s=…&v=…`).
        // Strip the query string + leading slash, then route through one of
        // two paths depending on whether it's our own Glide cache URL.
        if (str_starts_with($rawMark, '/')) {
            $path = $rawMark;
            $qpos = strpos($path, '?');
            if ($qpos !== false) {
                $path = substr($path, 0, $qpos);
            }
            $path = ltrim($path, '/');

            // Own Glide cache URL — short-circuit to the SOURCE file rather
            // than the cached variant. The variant only exists on disk after
            // the browser has fetched it once; using it as a watermark
            // server-side runs before that, so the cache file is missing
            // and Glide silently no-ops. Routing to the source means the
            // watermark always resolves regardless of fetch order.
            $cacheRel = self::relativeAddonAssetsCacheDir();
            if (str_starts_with($path, $cacheRel)) {
                return $this->resolveOwnCachePath(substr($path, strlen($cacheRel)));
            }

            return $path;
        }

        // Plain mediapool filename → resolve under the mediapool root.
        return 'media/' . $rawMark;
    }

    /**
     * Translate a Glide cache path tail (everything after
     * `assets/addons/massif_media/cache/`) back to its source file under the
     * watermarks FS root.
     *
     * Tail shape: `<src>/<spec>.<ext>`
     *   - `<src>` mediapool: relative filename (`viterex.png`,
     *     `subdir/logo.png`) → `media/<src>`
     *   - `<src>` external:  `_external/<hash>` → look up the manifest, get
     *     the origin's relative path under frontend.
     */
    private function resolveOwnCachePath(string $tail): ?string
    {
        $lastSlash = strrpos($tail, '/');
        if ($lastSlash === false) {
            return null;
        }
        $src = substr($tail, 0, $lastSlash);
        if ($src === '') {
            return null;
        }

        if (str_starts_with($src, '_external/')) {
            $hash = substr($src, strlen('_external/'));
            return $this->resolveExternalByHash($hash);
        }

        return 'media/' . $src;
    }

    private function resolveExternalByHash(string $hash): ?string
    {
        // Re-use the manifest lookup the Endpoint does for external sources.
        // Network-IO-free: ExternalManifest::read just opens the JSON sidecar
        // already on disk; no fetch happens here.
        try {
            $manifest = ExternalManifest::read($hash);
        } catch (Throwable $e) {
            rex_logger::logException($e);
            return null;
        }
        if ($manifest === null) {
            return null;
        }
        $origin = ExternalManifest::originPath($hash);
        return self::relativeToFrontend($origin);
    }

    private static function relativeAddonAssetsCacheDir(): string
    {
        return self::relativeToFrontend(rex_path::addonAssets(Config::ADDON, 'cache/')) ?? 'assets/addons/massif_media/cache/';
    }

    private static function relativeToFrontend(string $absolute): ?string
    {
        $frontend = rex_path::frontend();
        if (!str_starts_with($absolute, $frontend)) {
            return null;
        }
        return ltrim(substr($absolute, strlen($frontend)), '/');
    }

    private function resolveExternal(string $url): ?string
    {
        $resolver = $this->externalResolver
            ?? static fn (string $u): ExternalSource => (new ExternalSourceFactory())->resolve($u);
        try {
            $source = $resolver($url);
        } catch (Throwable $e) {
            rex_logger::logException($e);
            return null;
        }

        // Compute the path of the fetched origin relative to
        // rex_path::frontend() — that's the root the Glide watermarks FS is
        // anchored to (see {@see Server::create()}). Done via substring
        // rather than hardcoding `assets/addons/massif_media/...` so a
        // custom REDAXO assets dir still produces the right relative path.
        $absoluteOrigin = $source->absolutePath();
        $frontend = rex_path::frontend();
        if (!str_starts_with($absoluteOrigin, $frontend)) {
            // Origin landed outside rex_path::frontend() — shouldn't happen
            // with the standard layout but guard against a misconfigured
            // assets directory rather than handing Glide an invalid path.
            return null;
        }
        return ltrim(substr($absoluteOrigin, strlen($frontend)), '/');
    }
}
