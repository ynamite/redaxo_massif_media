<?php

declare(strict_types=1);

// Composer autoload (production + dev classes via autoload-dev).
require __DIR__ . '/../vendor/autoload.php';

// Register minimal REDAXO core class stubs if the real ones aren't loaded.
require __DIR__ . '/_stubs/redaxo.php';

// Convert PHP runtime notices/warnings/deprecations into ErrorExceptions during
// the test run so they surface as test failures rather than silently passing.
set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});
