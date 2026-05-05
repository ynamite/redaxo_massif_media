<?php

declare(strict_types=1);

namespace Ynamite\Media\Pipeline;

use rex_file;
use rex_logger;
use rex_path;
use Throwable;
use Ynamite\Media\Config;
use Ynamite\Media\Glide\Server;

final class Placeholder
{
    /**
     * Bumped whenever the LQIP encoding contract changes (format, metadata
     * stripping, …) so existing _lqip/*.txt files self-invalidate without
     * needing a manual cache clear.
     *   v1: jpg, metadata included
     *   v2: webp, EXIF/XMP/ICC stripped
     */
    private const CACHE_VERSION = 'v2';

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

        $cachePath = $this->cacheFile($image);
        if (is_file($cachePath)) {
            $cached = (string) file_get_contents($cachePath);
            if ($cached !== '') {
                return $cached;
            }
        }

        try {
            $server = Server::create();
            $relCachePath = $server->makeImage($image->sourcePath, [
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

    private function cacheFile(ResolvedImage $image): string
    {
        return self::cachePathFor($image->sourcePath, $image->mtime);
    }

    public static function cachePathFor(string $filename, int $mtime): string
    {
        $hash = hash('xxh64', $filename . ':' . $mtime . ':' . self::CACHE_VERSION);
        return rex_path::addonAssets(
            Config::ADDON,
            'cache/_lqip/' . substr($hash, 0, 2) . '/' . $hash . '.txt'
        );
    }
}
