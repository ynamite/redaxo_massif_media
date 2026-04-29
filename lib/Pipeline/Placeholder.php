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
     * Generate (or load cached) an inline base64 LQIP for an image.
     * Returns a `data:image/jpeg;base64,...` URI, or '' when LQIP is disabled,
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
                'fm' => 'jpg',
            ]);
            $bytes = $server->getCache()->read($relCachePath);
        } catch (Throwable $e) {
            rex_logger::logException($e);
            return '';
        }

        $dataUri = 'data:image/jpeg;base64,' . base64_encode($bytes);
        rex_file::put($cachePath, $dataUri);
        return $dataUri;
    }

    private function cacheFile(ResolvedImage $image): string
    {
        $hash = hash('xxh64', $image->sourcePath . ':' . $image->mtime);
        return rex_path::addonAssets(
            Config::ADDON,
            'cache/_lqip/' . substr($hash, 0, 2) . '/' . $hash . '.txt'
        );
    }
}
