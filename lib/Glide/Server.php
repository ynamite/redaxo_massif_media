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
     *
     * Two shapes:
     * - No crop (params['h'] / params['fit'] absent): {fmt}-{w}-{q}/{source_path}.{out_ext}
     *   (legacy shape, preserved for backward compatibility with existing on-disk cache)
     * - Crop:    {fmt}-{w}-{h}-{fitToken}-{q}/{source_path}.{out_ext}
     *
     * `fitToken` follows our internal vocabulary: `cover-{X}-{Y}` (focal-aware), `contain`, or `stretch`.
     * Endpoint::parseCachePath understands both shapes and rejects malformed paths.
     */
    public static function cachePath(string $path, array $params): string
    {
        $fmt = strtolower((string) ($params['fm'] ?? pathinfo($path, PATHINFO_EXTENSION)));
        $w = (int) ($params['w'] ?? 0);
        $q = (int) ($params['q'] ?? 80);
        $h = isset($params['h']) ? (int) $params['h'] : null;
        $fitToken = isset($params['fit']) && is_string($params['fit']) && $params['fit'] !== ''
            ? $params['fit']
            : null;

        // Normalize Glide's `crop-X-Y` (passed in when this is invoked from
        // inside makeImage via the cachePathCallable) back to our `cover-X-Y`
        // (used in URL emission). Both code paths must produce the same path
        // so static direct-serving works on cache hits — otherwise Glide would
        // write to `crop-X-Y/` while the browser asks for `cover-X-Y/`.
        if ($fitToken !== null && str_starts_with($fitToken, 'crop-')) {
            $fitToken = 'cover-' . substr($fitToken, strlen('crop-'));
        }

        if ($h !== null && $h > 0 && $fitToken !== null) {
            return sprintf('%s-%d-%d-%s-%d/%s.%s', $fmt, $w, $h, $fitToken, $q, $path, $fmt);
        }

        return sprintf('%s-%d-%d/%s.%s', $fmt, $w, $q, $path, $fmt);
    }
}
