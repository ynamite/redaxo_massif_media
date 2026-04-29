<?php

declare(strict_types=1);

use Ynamite\Media\Config;

if (!Config::signKey()) {
    Config::set(Config::KEY_SIGN_KEY, bin2hex(random_bytes(32)));
}

$cacheDir = rex_path::addonAssets(Config::ADDON, 'cache/');
if (!is_dir($cacheDir)) {
    rex_dir::create($cacheDir);
}
