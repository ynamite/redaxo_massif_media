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
    /**
     * Active filter params for the current request. Set by Endpoint::handle
     * before each makeImage call so the Glide-bound cachePathCallable closure
     * can spread them into Server::cachePath. Public because the closure runs
     * with League\Glide\Server's scope after Glide's Closure::bind, so private
     * access would fail (see Glide gotcha in CLAUDE.md).
     *
     * @var array<string, scalar>
     */
    public static array $activeFilterParams = [];

    /**
     * When true, the StripMetadata manipulator will strip EXIF / XMP / IPTC
     * / ICC profile from the encoded output. Set by Pipeline\Placeholder
     * before its makeImage call (and cleared in finally). Same public-static
     * pattern as $activeFilterParams.
     */
    public static bool $activeStripMetadata = false;

    public static function setActiveFilters(array $params): void
    {
        self::$activeFilterParams = $params;
    }

    public static function clearActiveFilters(): void
    {
        self::$activeFilterParams = [];
    }

    public static function setActiveStripMetadata(bool $on): void
    {
        self::$activeStripMetadata = $on;
    }

    public static function clearActiveStripMetadata(): void
    {
        self::$activeStripMetadata = false;
    }

    public static function create(?string $sourceDir = null, ?string $cacheDir = null): GlideServer
    {
        $sourceDir ??= rex_path::media();
        $cacheDir ??= rex_path::addonAssets(Config::ADDON, 'cache/');

        $sourceFs = new Filesystem(new LocalFilesystemAdapter($sourceDir));
        $cacheFs = new Filesystem(new LocalFilesystemAdapter($cacheDir));

        $driver = extension_loaded('imagick') ? 'imagick' : 'gd';

        $server = ServerFactory::create([
            'source' => $sourceFs,
            'cache' => $cacheFs,
            'driver' => $driver,
        ]);

        $server->setCachePathCallable(self::cachePathCallable());

        // Append custom manipulators after Glide's defaults so they run on
        // the final pixels before encoding. ColorProfile normalizes to sRGB;
        // StripMetadata is request-gated (only fires for the LQIP path).
        $api = $server->getApi();
        $manipulators = $api->getManipulators();
        $manipulators[] = new ColorProfile();
        $manipulators[] = new StripMetadata();
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
        //
        // The closure additionally reads Server::$activeFilterParams so on-disk
        // paths match the URL emission's filter hash even when Glide internally
        // calls Server::cachePath without filter context.
        return fn (string $path, array $params): string => Server::cachePath($path, [
            ...$params,
            'filters' => Server::$activeFilterParams,
        ]);
    }

    /**
     * Compute the cache path for a given source + params.
     *
     * Asset-keyed: {src}/{transformSpec}.{ext}.
     *
     * Four shapes for the transform spec:
     *   {fmt}-{w}-{q}                                  — no crop, no filters
     *   {fmt}-{w}-{h}-{fitToken}-{q}                   — crop, no filters
     *   {fmt}-{w}-{q}-f{hash}                          — no crop, with filters
     *   {fmt}-{w}-{h}-{fitToken}-{q}-f{hash}           — crop, with filters
     *
     * fitToken is `cover-{X}-{Y}` / `contain` / `stretch`.
     * Glide's `crop-X-Y` (passed in when invoked from inside makeImage via the
     * cachePathCallable) is normalized back to our `cover-X-Y` form so URL-side
     * and Glide-side produce the same path.
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

        if ($fitToken !== null && str_starts_with($fitToken, 'crop-')) {
            $fitToken = 'cover-' . substr($fitToken, strlen('crop-'));
        }

        $filterParams = isset($params['filters']) && is_array($params['filters']) && $params['filters'] !== []
            ? $params['filters']
            : null;
        $hash = $filterParams !== null ? CacheKeyBuilder::hashFilterParams($filterParams) : null;

        $spec = sprintf('%s-%d', $fmt, $w);
        if ($h !== null && $h > 0 && $fitToken !== null) {
            $spec .= sprintf('-%d-%s', $h, $fitToken);
        }
        $spec .= '-' . $q;
        if ($hash !== null) {
            $spec .= '-f' . $hash;
        }

        return sprintf('%s/%s.%s', $path, $spec, $fmt);
    }
}
