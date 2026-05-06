<?php

declare(strict_types=1);

// REDAXO core's boot.php lives at a path that depends on the active path
// provider — `<htdocs>/redaxo/src/core/boot.php` on standard installs, but
// `<project>/src/core/boot.php` on layouts that put htdocs in a subdirectory
// (Viterex, custom `app_path_provider`, etc.). We can't ask `rex_path::core()`
// at this point because REDAXO isn't loaded yet, so install.php resolves the
// path via the live path provider and writes it to the sibling `.config.php`.
$config = @include __DIR__ . '/.config.php';
if (!is_array($config) || !isset($config['boot']) || !is_file($config['boot'])) {
    http_response_code(500);
    exit('massif_media: _img/.config.php missing or invalid — reinstall the addon');
}

require $config['boot'];

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
