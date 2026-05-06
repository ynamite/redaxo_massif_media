<?php

declare(strict_types=1);

// Composer registers its ClassLoader with prepend=true by default, which would
// place our `Psr\Log\*` (v3) ahead of REDAXO core's bundled psr/log on the SPL
// chain. On REDAXO < 5.18 (psr/log v1 in core), `rex_logger::log()` is declared
// without the `: void` return type and without `string|\Stringable $message`
// typing, so it can't extend our v3 `Psr\Log\AbstractLogger` — PHP throws a
// fatal "Declaration must be compatible" the moment `rex_logger` is loaded.
// Re-registering as appended lets REDAXO's loader resolve `Psr\Log\*` first
// (matching whatever version its `rex_logger` was authored against), while our
// loader still owns every namespace REDAXO doesn't ship (`Symfony\…`, `League\…`,
// `Ynamite\Media\…`, etc.).
$massifMediaLoader = require __DIR__ . '/vendor/autoload.php';
if ($massifMediaLoader instanceof \Composer\Autoload\ClassLoader) {
    $massifMediaLoader->unregister();
    $massifMediaLoader->register(false);
}
unset($massifMediaLoader);

use Ynamite\Media\Config;
use Ynamite\Media\Glide\RequestHandler;
use Ynamite\Media\Pipeline\CacheInvalidator;
use Ynamite\Media\Pipeline\Preloader;
use Ynamite\Media\Var\RexPic;
use Ynamite\Media\Var\RexVideo;

// REX_PIC[...] / REX_VIDEO[...] in slice content → <picture> / <video> markup.
// Native rex_vars, scoped to article rendering — do not fire on backend pages
// (e.g. the addon's own Documentation tab) and do not regex every byte of
// every output.
rex_var::register('REX_PIC', RexPic::class);
rex_var::register('REX_VIDEO', RexVideo::class);

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

// MEDIA_UPDATED / MEDIA_DELETED → drop the per-asset cache so the next render
// rebuilds with fresh metadata. Critical for focal-point edits, which only
// touch a database column (file mtime stays the same → meta-cache hash stays
// the same → cached entry returns the OLD focal point unless we explicitly
// invalidate). Same closure handles both EPs — payload shape is identical
// (params['filename']).
$invalidateMediaCache = static function (rex_extension_point $ep): void {
    $filename = $ep->getParam('filename');
    if (is_string($filename) && $filename !== '') {
        CacheInvalidator::invalidate($filename);
    }
};
rex_extension::register('MEDIA_UPDATED', $invalidateMediaCache);
rex_extension::register('MEDIA_DELETED', $invalidateMediaCache);
