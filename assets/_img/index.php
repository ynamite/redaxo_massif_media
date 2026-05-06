<?php

declare(strict_types=1);

// REDAXO core's boot.php requires three globals (`$REX['HTDOCS_PATH']`,
// `$REX['BACKEND_FOLDER']`, `$REX['REDAXO']`), and on installs with a custom
// path provider (Viterex `app_path_provider`, etc.) also `$REX['PATH_PROVIDER']`.
// Since none of `rex_path::*` is available before boot.php runs, install.php
// resolves all of that via the live path provider and writes a self-contained
// bootstrap script to `_img/.bootstrap.php`.
if (!is_file(__DIR__ . '/.bootstrap.php')) {
    http_response_code(500);
    exit('massif_media: _img/.bootstrap.php missing — reinstall the addon');
}

require __DIR__ . '/.bootstrap.php';

if (!class_exists('rex_addon')) {
    http_response_code(500);
    exit('REDAXO core not booted');
}

rex_addon::get('massif_media')->boot();

if (!class_exists('Ynamite\\Media\\Glide\\Endpoint')) {
    http_response_code(500);
    exit('massif_media not loaded');
}

\Ynamite\Media\Glide\Endpoint::handle();
