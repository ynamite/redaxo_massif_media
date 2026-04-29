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

        return $server;
    }

    public static function cachePathCallable(): Closure
    {
        return static fn (string $path, array $params): string => self::cachePath($path, $params);
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
