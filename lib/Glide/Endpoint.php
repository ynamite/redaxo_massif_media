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
            $relCachePath = $server->makeImage($parsed['source'], [
                'w' => $parsed['w'],
                'q' => $parsed['q'],
                'fm' => $parsed['fmt'],
            ]);
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
     * Parse "{fmt}-{w}-{q}/{source}.{out_ext}" into its components.
     *
     * @return array{fmt: string, w: int, q: int, source: string}|null
     */
    public static function parseCachePath(string $path): ?array
    {
        $segments = explode('/', $path, 2);
        if (count($segments) < 2) {
            return null;
        }
        [$paramSeg, $rest] = $segments;

        if (!preg_match('/^([a-z0-9]+)-(\d+)-(\d+)$/', $paramSeg, $m)) {
            return null;
        }
        $fmt = $m[1];
        $w = (int) $m[2];
        $q = (int) $m[3];

        $source = preg_replace('/\.' . preg_quote($fmt, '/') . '$/i', '', $rest);
        if ($source === null || $source === $rest) {
            return null;
        }

        return ['fmt' => $fmt, 'w' => $w, 'q' => $q, 'source' => $source];
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
