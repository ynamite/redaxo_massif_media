<?php

declare(strict_types=1);

namespace Ynamite\Media\Glide;

use Closure;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use League\Flysystem\Visibility;
use League\Glide\Server as GlideServer;
use League\Glide\ServerFactory;
use rex_path;
use Ynamite\Media\Config;
use Ynamite\Media\Source\ExternalSource;
use Ynamite\Media\Source\MediapoolSource;
use Ynamite\Media\Source\SourceInterface;

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

    public static function setActiveFilters(array $params): void
    {
        self::$activeFilterParams = $params;
    }

    public static function clearActiveFilters(): void
    {
        self::$activeFilterParams = [];
    }

    /**
     * Build a Glide Server configured for the given source. Dispatches by
     * source type:
     *
     *   - {@see MediapoolSource}: source FS rooted at `rex_path::media()`,
     *     cache FS at `cache/`, cache-path callable produces
     *     `{filename}/{spec}.{ext}`.
     *   - {@see ExternalSource}:  source FS rooted at the per-URL bucket
     *     `cache/_external/<hash>/`, cache FS at the same dir, cache-path
     *     callable produces a flat `{spec}.{ext}` (no extra nesting under
     *     `_origin.bin`).
     */
    public static function for(SourceInterface $source): GlideServer
    {
        return $source instanceof ExternalSource
            ? self::createForExternal($source)
            : self::createForMediapool();
    }

    /**
     * Path string to pass to `$server->makeImage(...)`. Always relative to
     * the source filesystem of the {@see Server::for()} return value.
     *
     *   - Mediapool: relative filename (the source key).
     *   - External:  `_origin.bin` (constant; the per-bucket source FS only
     *     contains the one fetched origin).
     */
    public static function glideSourcePath(SourceInterface $source): string
    {
        return $source instanceof ExternalSource
            ? '_origin.bin'
            : $source->key();
    }

    public static function create(?string $sourceDir = null, ?string $cacheDir = null): GlideServer
    {
        $sourceDir ??= rex_path::media();
        $cacheDir ??= rex_path::addonAssets(Config::ADDON, 'cache/');

        $sourceFs = new Filesystem(new LocalFilesystemAdapter($sourceDir));
        $cacheFs = new Filesystem(new LocalFilesystemAdapter($cacheDir, self::publicVisibility()));
        // Watermarks FS is rooted at `rex_path::frontend()` — the proper
        // REDAXO anchor for both `media/` and `assets/` subdirs (REDAXO
        // resolves both via `frontend()` internally). Using `rex_path::base()`
        // would break in installers like Viterex that override `frontend()`
        // to `<base>/public/` — there `<base>/media/` doesn't exist; the
        // actual mediapool is at `<base>/public/media/`. Path translation
        // lives in {@see \Ynamite\Media\Pipeline\WatermarkResolver}; without
        // any watermarks FS at all, Glide's Watermark manipulator returns
        // the unmodified image (its `getImage()` short-circuits on a null
        // filesystem) and `mark`/`markpos`/... params are silently ignored.
        $watermarksFs = new Filesystem(new LocalFilesystemAdapter(rex_path::frontend()));

        $driver = extension_loaded('imagick') ? 'imagick' : 'gd';

        $server = ServerFactory::create([
            'source' => $sourceFs,
            'cache' => $cacheFs,
            'watermarks' => $watermarksFs,
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
        $api->setEncoder(new SafeAvifEncoder());

        return $server;
    }

    /**
     * Mediapool variant — equivalent to the historical {@see Server::create()}
     * defaults. Source FS at `rex_path::media()`, cache FS at our addon's
     * cache directory.
     */
    public static function createForMediapool(): GlideServer
    {
        return self::create();
    }

    /**
     * External variant — per-bucket Glide server. Source and cache filesystems
     * are both rooted at `cache/_external/<hash>/`, so:
     *
     *   - `makeImage('_origin.bin', $params)` reads `cache/_external/<hash>/_origin.bin`
     *   - the cache-path callable produces `<spec>.<ext>` (no path prefix), so
     *     variants land at `cache/_external/<hash>/<spec>.<ext>` — matching the
     *     URL emission's path which uses `_external/<hash>` as the cache-bucket
     *     directory portion.
     *
     * Manipulators (ColorProfile, StripMetadata) match the mediapool server so
     * encoding behaves identically across source types.
     */
    public static function createForExternal(ExternalSource $source): GlideServer
    {
        $bucketDir = rex_path::addonAssets(Config::ADDON, 'cache/_external/' . $source->hash . '/');

        $sourceFs = new Filesystem(new LocalFilesystemAdapter($bucketDir, self::publicVisibility()));
        $cacheFs = new Filesystem(new LocalFilesystemAdapter($bucketDir, self::publicVisibility()));
        // Same watermarks-FS rationale as {@see Server::create()}: rooted at
        // `rex_path::frontend()` (the REDAXO public anchor that custom
        // installers like Viterex offset to `<base>/public/`) so
        // {@see \Ynamite\Media\Pipeline\WatermarkResolver} can address both
        // mediapool marks (`media/logo.png`) and fetched external marks
        // (`assets/addons/.../cache/_external/<hash>/_origin.bin`) through
        // one filesystem regardless of the project layout.
        $watermarksFs = new Filesystem(new LocalFilesystemAdapter(rex_path::frontend()));

        $driver = extension_loaded('imagick') ? 'imagick' : 'gd';

        $server = ServerFactory::create([
            'source' => $sourceFs,
            'cache' => $cacheFs,
            'watermarks' => $watermarksFs,
            'driver' => $driver,
        ]);

        $server->setCachePathCallable(self::externalCachePathCallable());

        $api = $server->getApi();
        $manipulators = $api->getManipulators();
        $manipulators[] = new ColorProfile();
        $manipulators[] = new StripMetadata();
        $api->setManipulators($manipulators);
        $api->setEncoder(new SafeAvifEncoder());

        return $server;
    }

    /**
     * Force PUBLIC default visibility on cache filesystems. Flysystem's
     * `PortableVisibilityConverter` defaults to PRIVATE for new directories
     * (mode 0700), which on shared hosting (Plesk, cPanel) breaks: PHP-FPM
     * runs as one user, Apache as another, and Apache cannot traverse the
     * 0700 directory the FPM user just created — every cache hit returns 403
     * (`pcfg_openfile: unable to check htaccess file, ensure it is readable
     * and that the directory is executable`). Forcing PUBLIC gives 0755 dirs
     * and 0644 files, which is the right shape for files served by the web
     * server. Source FS stays on the default — the source tree is REDAXO's
     * mediapool, whose perms are not ours to set.
     */
    private static function publicVisibility(): PortableVisibilityConverter
    {
        return PortableVisibilityConverter::fromArray([], Visibility::PUBLIC);
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
     * Cache-path callable for the external Glide server. The path passed in is
     * always `_origin.bin` (per {@see Server::glideSourcePath()}), but variants
     * are stored flat under `cache/_external/<hash>/` — so we strip the path
     * entirely and emit just `<spec>.<ext>`. The bucket directory itself is
     * the `<src>` portion of the URL-side cache path; this callable fills in
     * only the filename half.
     */
    public static function externalCachePathCallable(): Closure
    {
        return fn (string $path, array $params): string => Server::cacheSpecWithExt([
            ...$params,
            'filters' => Server::$activeFilterParams,
        ]);
    }

    /**
     * Compute the cache path for a given source key + params.
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
        // If `fm` is absent, fall back to the source path's extension so a
        // `Server::cachePath('hero.png', ['w' => 1080, 'q' => 50])` call still
        // derives the format. Mirrors the historical behaviour the URL builder
        // relies on for callers that pass a Glide makeImage path verbatim.
        if (!isset($params['fm']) || $params['fm'] === '') {
            $ext = pathinfo($path, PATHINFO_EXTENSION);
            if ($ext !== '') {
                $params['fm'] = $ext;
            }
        }
        return $path . '/' . self::cacheSpecWithExt($params);
    }

    /**
     * The filename half of the cache path — `{fmt}-{w}[-{h}-{fit}]-{q}[-f{hash}].{ext}`.
     * Shared between mediapool ({@see Server::cachePath()}) and external
     * ({@see Server::externalCachePathCallable()}) flows so the on-disk spec
     * format stays in lockstep across source types.
     */
    public static function cacheSpecWithExt(array $params): string
    {
        $fmt = strtolower((string) ($params['fm'] ?? 'jpg'));
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
        $spec .= '.' . $fmt;

        return $spec;
    }
}
