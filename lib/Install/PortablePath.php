<?php

declare(strict_types=1);

namespace Ynamite\Media\Install;

/**
 * Turns an absolute path into a PHP source expression that resolves it
 * relative to a given directory (`__DIR__` of the generated file). Keeps
 * generated bootstrap files portable across machines and deploy targets,
 * where the absolute prefix differs but the relative layout is identical.
 */
final class PortablePath
{
    /**
     * @param non-empty-string $fromDir absolute directory the generated file lives in
     * @param non-empty-string $target absolute path to reference
     *
     * @return non-empty-string PHP expression, e.g. `__DIR__ . '/../../src/core/boot.php'`
     */
    public static function export(string $fromDir, string $target): string
    {
        $from = explode('/', rtrim(str_replace('\\', '/', $fromDir), '/'));
        $to = explode('/', rtrim(str_replace('\\', '/', $target), '/'));

        $shared = 0;
        while (isset($from[$shared], $to[$shared]) && $from[$shared] === $to[$shared]) {
            ++$shared;
        }

        // No common root beyond "/" (other volume/drive) — relative is impossible.
        if ($shared < 2) {
            return var_export($target, true);
        }

        $up = str_repeat('/..', count($from) - $shared);
        $down = implode('/', array_slice($to, $shared));

        return "__DIR__ . " . var_export($up . '/' . $down, true);
    }
}
