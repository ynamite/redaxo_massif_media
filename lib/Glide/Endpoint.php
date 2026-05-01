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

        if ($cachePath === '' || !Signature::verify($cachePath, $signature)) {
            self::respond(403, 'Forbidden');
            return;
        }

        $parsed = self::parseCachePath($cachePath);
        if ($parsed === null) {
            self::respond(400, 'Bad request');
            return;
        }

        try {
            $server = Server::create();
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
            $relCachePath = $server->makeImage($parsed['source'], $params);
            $bytes = $server->getCache()->read($relCachePath);
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
     * Parse a cache path back into its components. Accepts both shapes:
     *
     * - Legacy: `{fmt}-{w}-{q}/{source}.{out_ext}` — h and fit are null.
     * - Crop:   `{fmt}-{w}-{h}-{fitToken}-{q}/{source}.{out_ext}` — fitToken is
     *   `cover-{X}-{Y}` / `contain` / `stretch`.
     *
     * @return array{fmt: string, w: int, q: int, h: int|null, fit: string|null, source: string}|null
     */
    public static function parseCachePath(string $path): ?array
    {
        $segments = explode('/', $path, 2);
        if (count($segments) < 2) {
            return null;
        }
        [$paramSeg, $rest] = $segments;

        $tokens = explode('-', $paramSeg);
        if (count($tokens) < 3) {
            return null;
        }

        $fmt = $tokens[0];
        if (!preg_match('/^[a-z0-9]+$/', $fmt)) {
            return null;
        }

        // Legacy shape: fmt-w-q (3 tokens, last two numeric).
        if (count($tokens) === 3 && ctype_digit($tokens[1]) && ctype_digit($tokens[2])) {
            $source = self::stripFormatExtension($rest, $fmt);
            if ($source === null) {
                return null;
            }
            return [
                'fmt' => $fmt,
                'w' => (int) $tokens[1],
                'q' => (int) $tokens[2],
                'h' => null,
                'fit' => null,
                'source' => $source,
            ];
        }

        // Crop shape: fmt-w-h-{fitToken}-q (5+ tokens; tokens[1], tokens[2], and
        // last token are numeric; fitToken is the slice between h and q).
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

            $source = self::stripFormatExtension($rest, $fmt);
            if ($source === null) {
                return null;
            }
            return [
                'fmt' => $fmt,
                'w' => $w,
                'q' => $q,
                'h' => $h,
                'fit' => $fitToken,
                'source' => $source,
            ];
        }

        return null;
    }

    private static function stripFormatExtension(string $rest, string $fmt): ?string
    {
        $source = preg_replace('/\.' . preg_quote($fmt, '/') . '$/i', '', $rest);
        if ($source === null || $source === $rest) {
            return null;
        }
        return $source;
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
