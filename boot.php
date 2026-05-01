<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Ynamite\Media\Config;
use Ynamite\Media\Glide\RequestHandler;
use Ynamite\Media\Pipeline\Preloader;
use Ynamite\Media\Var\RexPic;

// REX_PIC[...] in slice content → <picture> markup. Native rex_var, scoped
// to article rendering — does not fire on backend pages (e.g. the addon's
// own Documentation tab) and does not regex every byte of every output.
rex_var::register('REX_PIC', RexPic::class);

// Self-contained cache-URL routing. On Apache (.htaccess) and standalone
// nginx (assets/nginx.conf.example), cache hits skip PHP entirely. On Herd
// or any setup without those optional rewrites, the request falls through
// to REDAXO's frontend index.php — this handler short-circuits there.
rex_extension::register('PACKAGES_INCLUDED', [RequestHandler::class, 'handle'], rex_extension::EARLY);

// Preload <link> injection into <head>. Drained once per request from the
// static Preloader queue populated by Image::preload() / ->preload().
rex_extension::register('OUTPUT_FILTER', static function (rex_extension_point $ep): void {
    $subject = $ep->getSubject();
    if (!is_string($subject)) {
        return;
    }

    $preloadLinks = Preloader::drain();
    if ($preloadLinks !== '' && stripos($subject, '</head>') !== false) {
        $subject = preg_replace(
            '/<\/head>/i',
            $preloadLinks . '</head>',
            $subject,
            1,
        ) ?? $subject;
        $ep->setSubject($subject);
    }
});

// CACHE_DELETED → wipe our cache contents (preserve the directory itself so
// future generations can write into it without re-creating it).
rex_extension::register('CACHE_DELETED', static function (): void {
    $cacheDir = rex_path::addonAssets(Config::ADDON, 'cache/');
    if (is_dir($cacheDir)) {
        rex_dir::delete($cacheDir, false);
    }
});
