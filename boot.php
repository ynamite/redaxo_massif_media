<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Ynamite\Media\Config;
use Ynamite\Media\Parser\REXPicParser;
use Ynamite\Media\Pipeline\Preloader;

// REX_PIC[...] substitution + preload <link> injection.
rex_extension::register('OUTPUT_FILTER', static function (rex_extension_point $ep): void {
    $subject = $ep->getSubject();
    if (!is_string($subject)) {
        return;
    }

    $subject = REXPicParser::process($subject);

    $preloadLinks = Preloader::drain();
    if ($preloadLinks !== '' && stripos($subject, '</head>') !== false) {
        $subject = preg_replace(
            '/<\/head>/i',
            $preloadLinks . '</head>',
            $subject,
            1,
        ) ?? $subject;
    }

    $ep->setSubject($subject);
});

// CACHE_DELETED → wipe our cache contents (preserve the directory itself so
// future generations can write into it without re-creating it).
rex_extension::register('CACHE_DELETED', static function (): void {
    $cacheDir = rex_path::addonAssets(Config::ADDON, 'cache/');
    if (is_dir($cacheDir)) {
        rex_dir::delete($cacheDir, false);
    }
});
