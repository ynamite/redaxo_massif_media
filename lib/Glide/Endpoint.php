<?php

declare(strict_types=1);

namespace Ynamite\Media\Glide;

use rex_logger;
use Throwable;

final class Endpoint
{
    public static function handle(): void
    {
        $cachePath = (string) ($_GET['p'] ?? '');
        $signature = (string) ($_GET['s'] ?? '');
        $filterBlob = (string) ($_GET['f'] ?? '');

        $extraPayload = $filterBlob !== '' ? $filterBlob : null;

        if ($cachePath === '' || !Signature::verify($cachePath, $signature, $extraPayload)) {
            self::respond(403, 'Forbidden');
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
            // Merge filter params last so they can't override w/q/fm/h/fit accidentally.
            $params = array_merge($filterParams, $params);

            Server::setActiveFilters($filterParams);
            try {
                $server = Server::create();
                $relCachePath = $server->makeImage($parsed['source'], $params);
                $bytes = $server->getCache()->read($relCachePath);
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
     * Parse asset-keyed cache path back into its components.
     *
     * Path shape: {src}/{transformSpec}.{ext}, with transformSpec being one of:
     *   - {fmt}-{w}-{q}                              — legacy (no crop, no filters)
     *   - {fmt}-{w}-{h}-{fitToken}-{q}               — crop, no filters
     *   - {fmt}-{w}-{q}-f{hash}                      — no crop, with filters
     *   - {fmt}-{w}-{h}-{fitToken}-{q}-f{hash}       — crop, with filters
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
