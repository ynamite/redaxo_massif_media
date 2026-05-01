<?php

declare(strict_types=1);

namespace Ynamite\Media\Glide;

use Closure;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Glide\Server as GlideServer;
use League\Glide\ServerFactory;
use rex_path;
use Ynamite\Media\Config;

final class Server
{
    public static function create(): GlideServer
    {
        $sourceFs = new Filesystem(new LocalFilesystemAdapter(rex_path::media()));
        $cacheFs = new Filesystem(new LocalFilesystemAdapter(rex_path::addonAssets(Config::ADDON, 'cache/')));

        $driver = extension_loaded('imagick') ? 'imagick' : 'gd';

        $server = ServerFactory::create([
            'source' => $sourceFs,
            'cache' => $cacheFs,
            'driver' => $driver,
        ]);

        $server->setCachePathCallable(self::cachePathCallable());

        // Append ColorProfile manipulator after Glide's defaults so colorspace
        // normalization runs on the final pixels before encoding.
        $api = $server->getApi();
        $manipulators = $api->getManipulators();
        $manipulators[] = new ColorProfile();
        $api->setManipulators($manipulators);

        return $server;
    }

    public static function cachePathCallable(): Closure
    {
        // Two Glide gotchas, both rooted in `Closure::bind($callable, $this, static::class)`:
        //   1. The closure must not be static — static closures reject `$this`
        //      and Glide throws "Cannot bind an instance to a static closure".
        //   2. The bind rescopes `self::` / `static::` to League\Glide\Server,
        //      so `self::cachePath()` resolves against Glide's class (which
        //      has no such method) and fails with
        //      "Call to undefined method League\Glide\Server::cachePath()".
        // Use the FQCN — resolved at the call site, not via the closure's
        // bound scope.
        return fn (string $path, array $params): string => Server::cachePath($path, $params);
    }

    /**
     * Compute the cache path for a given source + params.
     * Pattern: {fmt}-{w}-{q}/{source_path}.{out_ext}
     */
    public static function cachePath(string $path, array $params): string
    {
        $fmt = strtolower((string) ($params['fm'] ?? pathinfo($path, PATHINFO_EXTENSION)));
        $w = (int) ($params['w'] ?? 0);
        $q = (int) ($params['q'] ?? 80);

        return sprintf('%s-%d-%d/%s.%s', $fmt, $w, $q, $path, $fmt);
    }
}
