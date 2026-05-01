<?php

declare(strict_types=1);

namespace Ynamite\Media\Glide;

use rex;
use rex_response;

/**
 * Self-contained cache-URL request handler. Registered at PACKAGES_INCLUDED
 * (EARLY) so a request like /assets/addons/massif_media/cache/... that falls
 * through to REDAXO's frontend (nginx/Herd without rewrites, or any setup
 * lacking the addon's optional .htaccess fastpath) is intercepted before
 * yrewrite or content rendering runs.
 *
 * Apache + .htaccess and nginx + nginx.conf.example still serve cache hits
 * directly without booting REDAXO; this class handles the no-rewrite-config
 * case.
 *
 * Pattern mirrors `rex_media_manager::init` / `sendMedia` in REDAXO core.
 */
final class RequestHandler
{
    private const URI_PREFIX = '/assets/addons/massif_media/cache/';

    public static function handle(): void
    {
        if (rex::isBackend()) {
            return;
        }

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || !str_starts_with($path, self::URI_PREFIX)) {
            return;
        }

        $cachePath = substr($path, strlen(self::URI_PREFIX));
        if ($cachePath === '') {
            return;
        }

        $_GET['p'] = $cachePath;

        rex_response::cleanOutputBuffers();
        session_abort();

        Endpoint::handle();
        exit;
    }
}
