<?php

declare(strict_types=1);

namespace Ynamite\Media\Pipeline;

use rex_file;
use rex_logger;
use rex_path;
use Throwable;
use Ynamite\Media\Config;
use Ynamite\Media\Glide\Server;
use Ynamite\Media\Source\SourceInterface;

final class Placeholder
{
    /**
     * Bumped whenever the LQIP encoding contract changes (format, metadata
     * stripping, …) so existing _lqip/*.txt files self-invalidate without
     * needing a manual cache clear.
     *   v1: jpg, metadata included
     *   v2: webp, EXIF/XMP/ICC stripped
     *   v3: lqip config (width/quality/blur) folded into the cache key —
     *       makes the sidecar a pure function of bytes + config, so
     *       `_lqip/` can survive the generic CACHE_DELETED wipe like
     *       `_color/` does (config changes move the key instead of
     *       requiring a clear).
     */
    private const CACHE_VERSION = 'v3';

    /**
     * Generate (or load cached) an inline base64 LQIP for an image.
     * Returns a `data:image/webp;base64,...` URI, or '' when LQIP is disabled,
     * the source is non-rasterizable (svg/gif), or generation fails.
     */
    public function generate(ResolvedImage $image): string
    {
        if (!Config::lqipEnabled() || $image->isPassthrough()) {
            return '';
        }

        $cachePath = self::cachePathFor($image->source);
        if (is_file($cachePath)) {
            $cached = (string) file_get_contents($cachePath);
            if ($cached !== '') {
                return $cached;
            }
        }

        try {
            $server = Server::for($image->source);
            $relCachePath = $server->makeImage(Server::glideSourcePath($image->source), [
                'w' => Config::lqipWidth(),
                'q' => Config::lqipQuality(),
                'blur' => Config::lqipBlur(),
                'fm' => 'webp',
            ]);
            $bytes = $server->getCache()->read($relCachePath);
        } catch (Throwable $e) {
            rex_logger::logException($e);
            return '';
        }

        $dataUri = 'data:image/webp;base64,' . base64_encode($bytes);
        rex_file::put($cachePath, $dataUri);
        return $dataUri;
    }

    public static function cachePathFor(SourceInterface $source): string
    {
        $hash = hash('xxh64', implode(':', [
            $source->key(),
            $source->cacheBust(),
            self::CACHE_VERSION,
            Config::lqipWidth(),
            Config::lqipQuality(),
            Config::lqipBlur(),
        ]));
        return rex_path::addonAssets(
            Config::ADDON,
            'cache/_lqip/' . substr($hash, 0, 2) . '/' . $hash . '.txt'
        );
    }
}
