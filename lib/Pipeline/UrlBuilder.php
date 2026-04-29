<?php

declare(strict_types=1);

namespace Ynamite\Media\Pipeline;

use rex_url;
use Ynamite\Media\Config;
use Ynamite\Media\Glide\Server;
use Ynamite\Media\Glide\Signature;

final class UrlBuilder
{
    /**
     * Build a URL for a single variant of a resolved image.
     *
     * - When CDN is enabled: emits a CDN URL based on cdn_base + cdn_url_template.
     * - Otherwise: emits a signed local URL pointing at the addon's cache, with a
     *   ?v={mtime} parameter for browser / CDN cache busting on source changes.
     */
    public function build(ResolvedImage $image, int $width, string $format, ?int $quality = null): string
    {
        $quality ??= Config::quality($format);

        if (Config::cdnEnabled()) {
            return $this->buildCdnUrl($image, $width, $format, $quality);
        }

        $cachePath = Server::cachePath($image->sourcePath, [
            'fm' => $format,
            'w' => $width,
            'q' => $quality,
        ]);
        $signature = Signature::sign($cachePath);

        $url = rex_url::addonAssets(Config::ADDON, 'cache/' . $cachePath);
        $url .= '?s=' . $signature;
        if ($image->mtime > 0) {
            $url .= '&v=' . $image->mtime;
        }
        return $url;
    }

    /**
     * Build a CDN URL using the configured base and template.
     *
     * Template tokens: {w}, {h}, {q}, {fm}, {src}.
     * Example template: "tr:w-{w},q-{q},f-{fm}/{src}" (ImageKit-style).
     */
    private function buildCdnUrl(ResolvedImage $image, int $width, string $format, int $quality): string
    {
        $template = Config::cdnUrlTemplate();
        if ($template === '') {
            $template = '{src}?w={w}&q={q}&fm={fm}';
        }

        $expanded = strtr($template, [
            '{w}' => (string) $width,
            '{q}' => (string) $quality,
            '{fm}' => $format,
            '{src}' => $image->sourcePath,
        ]);

        $base = Config::cdnBase();
        return $base . '/' . ltrim($expanded, '/');
    }
}
