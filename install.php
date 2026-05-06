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

// REDAXO's installAssets() copies via rex_finder which has ignoreSystemStuff
// on by default — that filter drops anything starting with `.git` (intended
// for `.git/` directories, but also catches `.gitignore`). The shipped
// `assets/.gitignore` therefore never reaches the runtime location, so we
// write it from here. Lives one level above cache/ so neither the
// CACHE_DELETED hook nor the manual "Cache leeren" button (both of which
// recurse into cache/) can wipe it.
$gitignorePath = rex_path::addonAssets(Config::ADDON, '.gitignore');
if (!is_file($gitignorePath)) {
    rex_file::put($gitignorePath, "cache/\n");
}

// Article/template caches generated before this addon was active still contain
// `REX_PIC[...]` / `REX_VIDEO[...]` as literal text — at parse time the var
// wasn't registered, so `rex_var::parse` left them untouched. REDAXO doesn't
// auto-clear caches on install/activate, so flush them here to force a clean
// regen on next render. Also runs on update/reinstall, which is what we want
// when `getOutput()` semantics change between releases.
rex_delete_cache();
