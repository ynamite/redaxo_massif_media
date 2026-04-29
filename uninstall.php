<?php

declare(strict_types=1);

use Ynamite\Media\Config;

rex_config::removeNamespace(Config::ADDON);

$cacheDir = rex_path::addonAssets(Config::ADDON, 'cache/');
if (is_dir($cacheDir)) {
    rex_dir::delete($cacheDir);
}
