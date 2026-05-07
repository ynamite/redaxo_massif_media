<?php

declare(strict_types=1);

namespace Ynamite\Media\Glide;

use rex_logger;
use Throwable;
use Ynamite\Media\Pipeline\AnimatedWebpEncoder;
use Ynamite\Media\Pipeline\ImageResolver;
use Ynamite\Media\Pipeline\MetadataReader;
use Ynamite\Media\Pipeline\WatermarkResolver;
use Ynamite\Media\Source\ExternalSource;
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

        // Animated WebP variants live outside the Glide pipeline (Glide's
        // encoder is single-frame). Detect them first and dispatch to the
        // dedicated encoder; everything else falls through to Glide.
        if (str_ends_with($cachePath, '/animated.webp')) {
            self::handleAnimated($cachePath);
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

            Server::setActiveFilters($filterParams);
            try {
                if (str_starts_with($parsed['source'], '_external/')) {
                    $bytes = self::makeExternal($parsed['source'], $params);
                } else {
                    $server = Server::create();
                    $relCachePath = $server->makeImage($parsed['source'], $params);
                    $bytes = $server->getCache()->read($relCachePath);
                }
            } finally {
                Server::clearActiveFilters();
            }
        } catch (Throwable $e) {
            rex_logger::logException($e);
            self::respond(404, 'Not found');
            return;
        }

        $mime = self::mimeFor($parsed['fmt']);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: public, max-age=31536000, immutable');
        header('ETag: "' . md5($bytes) . '"');
        echo $bytes;
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
    private static function makeExternal(string $sourceKey, array $params): string
    {
        $hash = substr($sourceKey, strlen('_external/'));
        $factory = new ExternalSourceFactory();
        $source = $factory->resolveByHash($hash);
        if ($source === null) {
            throw new \RuntimeException('External source manifest not found for hash: ' . $hash);
        }

        $server = Server::createForExternal($source);
        $relCachePath = $server->makeImage(Server::glideSourcePath($source), $params);
        return $server->getCache()->read($relCachePath);
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

    private static function handleAnimated(string $cachePath): void
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

        try {
            $image = (new ImageResolver(new MetadataReader()))->resolve($src);
            $absPath = (new AnimatedWebpEncoder())->encode($image);
            if ($absPath === '' || !is_file($absPath)) {
                self::respond(404, 'Not found');
                return;
            }
            $bytes = (string) file_get_contents($absPath);
        } catch (Throwable $e) {
            rex_logger::logException($e);
            self::respond(404, 'Not found');
            return;
        }

        header('Content-Type: image/webp');
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: public, max-age=31536000, immutable');
        header('ETag: "' . md5($bytes) . '"');
        echo $bytes;
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
