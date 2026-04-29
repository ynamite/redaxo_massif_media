<?php

declare(strict_types=1);

require dirname(__DIR__, 4) . '/redaxo/src/core/boot.php';

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
