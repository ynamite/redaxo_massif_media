<?php

declare(strict_types=1);

namespace Ynamite\Media\Source;

use rex;

/**
 * Local-dev fallback for mediapool files that exist on the live site but not
 * on disk (media/ isn't synced down). The remote base is REDAXO's `server:`
 * from config.yml, used only when it points at a different host than the
 * current request — on production/staging `server:` matches the own host, so
 * nothing changes there. CLI (no request host), `.test`, `localhost` and
 * REDAXO's install default (`www.redaxo.org`) never fall back.
 */
final class RemoteMedia
{
    /** Remote `/media/<file>` URL, or null when no fallback applies. */
    public static function url(string $filename): ?string
    {
        $base = self::base((string) rex::getServer(), $_SERVER['HTTP_HOST'] ?? null);
        return $base === null || $filename === '' ? null : $base . 'media/' . rawurlencode($filename);
    }

    public static function base(string $server, ?string $requestHost): ?string
    {
        $host = self::normalizeHost((string) parse_url($server, PHP_URL_HOST));
        $requestHost = self::normalizeHost((string) preg_replace('/:\d+$/', '', (string) $requestHost));

        if (
            $host === ''
            || $requestHost === ''
            || $host === $requestHost
            || $host === 'localhost'
            || $host === 'redaxo.org'
            || str_ends_with($host, '.test')
        ) {
            return null;
        }

        return rtrim($server, '/') . '/';
    }

    /** Lowercase, `www.` stripped — `www.example.com` and `example.com` are the same site. */
    private static function normalizeHost(string $host): string
    {
        $host = strtolower($host);
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
