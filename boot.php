<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Ynamite\Media\Config;
use Ynamite\Media\Glide\RequestHandler;
use Ynamite\Media\Pipeline\CacheInvalidator;
use Ynamite\Media\Pipeline\Preloader;
use Ynamite\Media\Var\RexPic;
use Ynamite\Media\Var\RexVideo;
use Ynamite\Media\View\EditorContentScanner;

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

// OUTPUT_FILTER pass — two side-by-side concerns share the closure so we walk
// the full output once.
//   1. Replace literal `REX_PIC[…]` / `REX_VIDEO[…]` substrings the editor
//      pasted into rich-text fields. REDAXO core's `rex_var::parse` only runs
//      on module/article templates, so editor-input markers stay literal at
//      cache-build time; the post-render scan rewrites them to `<picture>` /
//      `<video>` markup. Cheap-skips when neither marker is present.
//   2. Drain the static Preloader queue (populated by Image::preload() /
//      ->preload()) and inject `<link rel="preload">` tags before `</head>`.
//
// Order matters: the scan pass may invoke `Image::picture(..., preload: true)`
// for editor-input REX_PIC tags carrying `preload="true"`, which queues
// preload links that must drain before the `</head>` injection runs.
rex_extension::register('OUTPUT_FILTER', static function (rex_extension_point $ep): void {
    $subject = $ep->getSubject();
    if (!is_string($subject)) {
        return;
    }

    $original = $subject;
    $subject = EditorContentScanner::scan($subject);

    $preloadLinks = Preloader::drain();
    if ($preloadLinks !== '' && stripos($subject, '</head>') !== false) {
        $subject = preg_replace(
            '/<\/head>/i',
            $preloadLinks . '</head>',
            $subject,
            1,
        ) ?? $subject;
    }

    if ($subject !== $original) {
        $ep->setSubject($subject);
    }
});

// CACHE_DELETED → wipe our cache contents (preserve the directory itself so
// future generations can write into it without re-creating it). Also bump
// the cache-generation token so the `&g=` segment in every emitted URL
// changes — without that, browser-cached variant responses (Cache-Control
// immutable, max-age 1y) keep being served from the local browser cache
// despite the server-side files being gone, and the server never sees a
// regen request. See lib/Pipeline/UrlBuilder.php and Config::cacheGeneration.
rex_extension::register('CACHE_DELETED', static function (): void {
    $cacheDir = rex_path::addonAssets(Config::ADDON, 'cache/');
    if (is_dir($cacheDir)) {
        rex_dir::delete($cacheDir, false);
    }
    Config::bumpCacheGeneration();
});

// Auto-clear-on-content-affecting-save lives in {@see \Ynamite\Media\Backend\ConfigForm}
// — `rex_config_form::save()` does not fire REX_FORM_SAVED (only `rex_form` does),
// so we subclass `rex_config_form` instead and the four settings pages
// (`pages/settings.{general,placeholder,cdn,security}.php`) instantiate the
// subclass directly. The HMAC sign key has its own dedicated regen button
// and is never auto-bumped on a settings save.

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
