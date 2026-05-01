<?php

/**
 * MASSIF Media — Laravel Herd / Valet integration snippet.
 *
 * Drop the `if (preg_match(...)) { ... }` block below into the existing
 * `frontControllerPath()` method of your site's LocalValetDriver.php,
 * placed AFTER the `$docRoot = rtrim($this->getPublicPath($sitePath), '/');`
 * line and BEFORE the candidates loop.
 *
 * Why this exists: Herd routes every parked site through Valet's `server.php`,
 * which dispatches based on `$_SERVER['REQUEST_URI']`. nginx-level rewrites
 * don't update `$request_uri`, so a `rewrite ... last;` in `herd.conf` has no
 * effect on Valet's routing — the cache-miss URL keeps falling through to the
 * site's frontend, which 404s. Intercepting here is the Valet-native fix.
 *
 * Cache hits keep the static-file fastpath: the existing `isStaticFile()`
 * implementation finds the cached variant on disk and Valet serves it
 * directly without entering this method.
 */

if (preg_match('#^/assets/addons/massif_media/cache/(.+)$#', $uri, $m)) {
    $shim = $docRoot . '/assets/addons/massif_media/_img/index.php';
    if ($this->isActualFile($shim)) {
        $_GET['p'] = $m[1];
        $_SERVER['SCRIPT_FILENAME'] = $shim;
        $_SERVER['SCRIPT_NAME'] = '/assets/addons/massif_media/_img/index.php';
        $_SERVER['DOCUMENT_ROOT'] = $docRoot;
        return $shim;
    }
}
