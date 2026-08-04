<?php

declare(strict_types=1);

namespace Ynamite\Media\Glide;

use rex_logger;
use rex_path;
use Throwable;
use Ynamite\Media\Config;
use Ynamite\Media\Pipeline\AnimatedWebpEncoder;
use Ynamite\Media\Pipeline\ImageResolver;
use Ynamite\Media\Pipeline\MetadataReader;
use Ynamite\Media\Pipeline\WatermarkResolver;
use Ynamite\Media\Source\ExternalSourceFactory;

final class Endpoint
{
    public static function handle(): void
    {
        // Force 0022 umask so new cache dirs come out 0755 and files 0644.
        // Flysystem's `LocalFilesystemAdapter` calls `mkdir($path, 0755, true)`
        // and `file_put_contents` without an explicit chmod; both apply the
        // process umask, so a system-wide umask 027 (common on Plesk shared
        // hosting) silently downgrades them to 0750 / 0640. On cross-user
        // setups (PHP-FPM as one user, Apache as another) Apache then 403s
        // every cache hit with `pcfg_openfile: ensure ... is executable`.
        // Pair with `Server::publicVisibility()` — the visibility config sets
        // the *intent* (0755 vs 0700), umask determines what `mkdir` actually
        // applies; both need to align. Restore in `finally` because
        // RequestHandler may be reused on misc requests where the original
        // umask shouldn't leak.
        $previousUmask = umask(0022);
        try {
            self::doHandle();
        } finally {
            umask($previousUmask);
        }
    }

    private static function doHandle(): void
    {
        $cachePath = (string) ($_GET['p'] ?? '');
        $signature = (string) ($_GET['s'] ?? '');
        $filterBlob = (string) ($_GET['f'] ?? '');

        $extraPayload = $filterBlob !== '' ? $filterBlob : null;

        if ($cachePath === '' || !Signature::verify($cachePath, $signature, $extraPayload)) {
            self::respond(403, 'Forbidden');
            return;
        }

        // On-disk location of the requested variant. The URL cache path IS the
        // on-disk path relative to cache/ — for mediapool via cachePathCallable
        // ({src}/{spec}.{ext}), for external via the per-bucket server whose
        // cache root is cache/_external/<hash>/ and relative path {spec}.{ext}.
        $abs = rex_path::addonAssets(Config::ADDON, 'cache/' . $cachePath);

        // Animated WebP variants live outside the Glide pipeline (Glide's
        // encoder is single-frame). Detect them first and dispatch to the
        // dedicated encoder; everything else falls through to Glide.
        if (str_ends_with($cachePath, '/animated.webp')) {
            self::handleAnimated($cachePath, $abs);
            return;
        }

        $parsed = self::parseCachePath($cachePath);
        if ($parsed === null) {
            self::respond(400, 'Bad request');
            return;
        }

        $filterParams = [];
        if ($parsed['hash'] !== null) {
            if ($filterBlob === '') {
                self::respond(400, 'Bad request');
                return;
            }
            $decoded = json_decode((string) CacheKeyBuilder::decodeFilterBlob($filterBlob), true);
            if (!is_array($decoded)) {
                self::respond(400, 'Bad request');
                return;
            }
            $filterParams = $decoded;
            $expectedHash = CacheKeyBuilder::hashFilterParams($filterParams);
            if (!hash_equals($expectedHash, $parsed['hash'])) {
                self::respond(400, 'Bad request');
                return;
            }
        }

        $mime = self::mimeFor($parsed['fmt']);

        // Cache hit: stream straight from disk — no Glide/Flysystem
        // instantiation, no full-file read into memory.
        if (self::isServable($abs)) {
            self::sendFile($abs, $mime);
            return;
        }

        try {
            $params = [
                'w' => $parsed['w'],
                'q' => $parsed['q'],
                'fm' => $parsed['fmt'],
            ];
            if ($parsed['h'] !== null) {
                $params['h'] = $parsed['h'];
            }
            if ($parsed['fit'] !== null) {
                // Translate our internal `cover-X-Y` token to Glide's `crop-X-Y`.
                $params['fit'] = str_starts_with($parsed['fit'], 'cover-')
                    ? 'crop-' . substr($parsed['fit'], strlen('cover-'))
                    : $parsed['fit'];
            }
            // Translate `mark` for Glide's actual file lookup (mediapool name
            // → "media/<name>", HTTPS URL → fetched-origin path under the
            // external cache bucket). The TRANSLATED mark goes into Glide's
            // makeImage params; the ORIGINAL untranslated $filterParams is
            // what setActiveFilters / hashFilterParams see, so the on-disk
            // cache path keyed by the URL-side hash stays consistent with
            // what UrlBuilder emitted. Translation failure (bad URL, fetch
            // error) drops the mark + companions silently — picture renders
            // without watermark rather than 500-ing on an editor typo.
            $processingFilterParams = self::translateMark($filterParams);

            // Merge filter params last so they can't override w/q/fm/h/fit accidentally.
            $params = array_merge($processingFilterParams, $params);

            // Per-variant lock: N concurrent requests for the same uncached
            // variant collapse into one encode; the waiters serve the freshly
            // written file after the lock releases. Without this, a cache
            // clear on a busy site produces an encode stampede — the same
            // multi-second AVIF encode running N× in parallel, each occupying
            // a PHP-FPM worker.
            $lock = self::lockVariant($abs);
            try {
                if (!self::isServable($abs)) {
                    Server::setActiveFilters($filterParams);
                    try {
                        if (str_starts_with($parsed['source'], '_external/')) {
                            self::makeExternal($parsed['source'], $params);
                        } else {
                            Server::create()->makeImage($parsed['source'], $params);
                        }
                    } finally {
                        Server::clearActiveFilters();
                    }
                }
            } finally {
                self::unlockVariant($lock);
            }
        } catch (Throwable $e) {
            rex_logger::logException($e);
            self::respond(404, 'Not found');
            return;
        }

        if (!self::isServable($abs)) {
            // Glide reported success but wrote somewhere else — cache-path
            // drift between UrlBuilder and cachePathCallable. Fail loudly:
            // a silent fallback would re-encode on every request forever.
            rex_logger::factory()->log(
                'error',
                'massif_media: generated variant missing at expected cache path: ' . $cachePath,
            );
            self::respond(404, 'Not found');
            return;
        }
        self::sendFile($abs, $mime);
    }

    private static function isServable(string $abs): bool
    {
        // filesize > 0 also refuses the known 0-byte-broken-AVIF shape
        // instead of serving an empty 200.
        return is_file($abs) && filesize($abs) > 0;
    }

    /**
     * Stream a cache file to the client. `X-Massif-Media: php` marks responses
     * served through the PHP handler — cache-hit URLs carrying this header
     * mean the static-serve fastpath (Apache .htaccess / nginx snippet) is
     * not active and every image request is paying a full REDAXO boot.
     */
    private static function sendFile(string $abs, string $mime): void
    {
        $mtime = (int) (filemtime($abs) ?: 0);
        $size = (int) (filesize($abs) ?: 0);
        $etag = '"' . dechex($mtime) . '-' . dechex($size) . '"';

        header('X-Massif-Media: php');
        header('Cache-Control: public, max-age=31536000, immutable');
        header('ETag: ' . $etag);

        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            return;
        }

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . $size);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        readfile($abs);
    }

    /**
     * Blocking exclusive lock on `<variant>.lock` next to the target cache
     * file. Returns the lock handle, or null when the lock file can't be
     * created (fail-open: encode without dedup rather than 500).
     *
     * @return resource|null
     */
    private static function lockVariant(string $abs)
    {
        $lockPath = $abs . '.lock';
        $dir = dirname($lockPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $fh = @fopen($lockPath, 'c');
        if ($fh === false) {
            return null;
        }
        @flock($fh, LOCK_EX);
        return $fh;
    }

    /**
     * @param resource|null $fh
     */
    private static function unlockVariant($fh): void
    {
        if (is_resource($fh)) {
            @flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * External-URL variant pipeline. The cache-path source is `_external/<hash>`;
     * we look up the persisted manifest to recover the URL, ensure the origin
     * body is present (re-fetch if expired or missing), then run the per-bucket
     * Glide server to produce the requested variant.
     *
     * The cache-bucket layout matches the URL emission's `_external/<hash>`
     * key (see {@see Server::createForExternal()} for the symmetry guarantee).
     */
    private static function makeExternal(string $sourceKey, array $params): void
    {
        $hash = substr($sourceKey, strlen('_external/'));
        $factory = new ExternalSourceFactory();
        $source = $factory->resolveByHash($hash);
        if ($source === null) {
            throw new \RuntimeException('External source manifest not found for hash: ' . $hash);
        }

        Server::createForExternal($source)->makeImage(Server::glideSourcePath($source), $params);
    }

    /**
     * Translate the `mark` filter value to a path Glide's watermarks FS can
     * resolve (rooted at `rex_path::base()` per {@see Server::create()}).
     * Local marks get a `media/` prefix; HTTPS URLs go through
     * {@see WatermarkResolver} → {@see ExternalSourceFactory} (SSRF + TTL +
     * cached origin). On translation failure, drops `mark` and its
     * companions (markpos / markpad / markalpha / marks / markw / markh /
     * markfit) so Glide doesn't render half-broken watermark state — the
     * picture comes out unwatermarked but otherwise correct.
     *
     * Only mutates the COPY for Glide processing — `$activeFilterParams`
     * (used by {@see Server::cachePathCallable}) keeps the original value
     * so the on-disk cache key matches the URL hash exactly.
     *
     * @param array<string,scalar> $filterParams
     * @return array<string,scalar>
     */
    private static function translateMark(array $filterParams): array
    {
        if (!isset($filterParams['mark']) || !is_string($filterParams['mark']) || $filterParams['mark'] === '') {
            return $filterParams;
        }

        $resolved = (new WatermarkResolver())->resolve($filterParams['mark']);
        if ($resolved === null) {
            unset(
                $filterParams['mark'],
                $filterParams['marks'],
                $filterParams['markw'],
                $filterParams['markh'],
                $filterParams['markpos'],
                $filterParams['markpad'],
                $filterParams['markalpha'],
                $filterParams['markfit'],
            );
            return $filterParams;
        }

        $filterParams['mark'] = $resolved;
        return $filterParams;
    }

    /**
     * Parse asset-keyed cache path back into its components.
     *
     * Path shape: {src}/{transformSpec}.{ext}, with transformSpec being one of:
     *   - {fmt}-{w}-{q}                              — legacy (no crop, no filters)
     *   - {fmt}-{w}-{h}-{fitToken}-{q}               — crop, no filters
     *   - {fmt}-{w}-{q}-f{hash}                      — no crop, with filters
     *   - {fmt}-{w}-{h}-{fitToken}-{q}-f{hash}       — crop, with filters
     *
     * `{src}` is either a mediapool relative filename (preserves subdirs) or
     * `_external/<hash>` for an external URL bucket.
     *
     * @return array{fmt: string, w: int, q: int, h: int|null, fit: string|null, hash: string|null, source: string}|null
     */
    public static function parseCachePath(string $path): ?array
    {
        $lastSlash = strrpos($path, '/');
        if ($lastSlash === false) {
            return null;
        }
        $srcPath = substr($path, 0, $lastSlash);
        $filename = substr($path, $lastSlash + 1);
        if ($srcPath === '' || $filename === '') {
            return null;
        }

        $extPos = strrpos($filename, '.');
        if ($extPos === false) {
            return null;
        }
        $stem = substr($filename, 0, $extPos);
        $ext = strtolower(substr($filename, $extPos + 1));
        if (!preg_match('/^[a-z0-9]+$/', $ext)) {
            return null;
        }

        $tokens = explode('-', $stem);
        if (count($tokens) < 3) {
            return null;
        }

        $fmt = $tokens[0];
        if (!preg_match('/^[a-z0-9]+$/', $fmt)) {
            return null;
        }

        // Detect optional trailing f{8-hex} segment.
        $hash = null;
        $last = $tokens[count($tokens) - 1];
        if (preg_match('/^f([a-f0-9]{8})$/', $last, $m)) {
            $hash = $m[1];
            array_pop($tokens);
        }

        // After potential hash strip: legacy fmt-w-q (3 tokens) or crop fmt-w-h-fit-q (5+).
        if (count($tokens) === 3 && ctype_digit($tokens[1]) && ctype_digit($tokens[2])) {
            return [
                'fmt' => $fmt,
                'w' => (int) $tokens[1],
                'q' => (int) $tokens[2],
                'h' => null,
                'fit' => null,
                'hash' => $hash,
                'source' => $srcPath,
            ];
        }

        if (count($tokens) >= 5
            && ctype_digit($tokens[1])
            && ctype_digit($tokens[2])
            && ctype_digit($tokens[count($tokens) - 1])
        ) {
            $w = (int) $tokens[1];
            $h = (int) $tokens[2];
            $q = (int) $tokens[count($tokens) - 1];
            $fitParts = array_slice($tokens, 3, count($tokens) - 4);
            $fitToken = implode('-', $fitParts);
            if (!self::isValidFitToken($fitToken)) {
                return null;
            }
            return [
                'fmt' => $fmt,
                'w' => $w,
                'q' => $q,
                'h' => $h,
                'fit' => $fitToken,
                'hash' => $hash,
                'source' => $srcPath,
            ];
        }

        return null;
    }

    private static function isValidFitToken(string $token): bool
    {
        return $token === 'contain'
            || $token === 'stretch'
            || (bool) preg_match('/^cover-\d{1,3}-\d{1,3}$/', $token);
    }

    private static function handleAnimated(string $cachePath, string $abs): void
    {
        $src = substr($cachePath, 0, -strlen('/animated.webp'));
        // Defensive: animated WebP isn't emitted for external sources
        // (UrlBuilder::buildAnimatedWebp short-circuits when isExternal()).
        // A request that arrives here for an `_external/...` path is malformed
        // — refuse rather than try to resolve.
        if ($src === '' || str_starts_with($src, '_external/')) {
            self::respond(400, 'Bad request');
            return;
        }

        if (self::isServable($abs)) {
            self::sendFile($abs, 'image/webp');
            return;
        }

        try {
            $lock = self::lockVariant($abs);
            try {
                if (!self::isServable($abs)) {
                    $image = (new ImageResolver(new MetadataReader()))->resolve($src);
                    (new AnimatedWebpEncoder())->encode($image);
                }
            } finally {
                self::unlockVariant($lock);
            }
        } catch (Throwable $e) {
            rex_logger::logException($e);
            self::respond(404, 'Not found');
            return;
        }

        if (!self::isServable($abs)) {
            self::respond(404, 'Not found');
            return;
        }
        self::sendFile($abs, 'image/webp');
    }

    private static function respond(int $code, string $body): void
    {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo $body;
    }

    private static function mimeFor(string $fmt): string
    {
        return match ($fmt) {
            'avif' => 'image/avif',
            'webp' => 'image/webp',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            default => 'application/octet-stream',
        };
    }
}
