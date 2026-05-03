<?php

declare(strict_types=1);

namespace Ynamite\Media\Pipeline;

use rex_url;
use Ynamite\Media\Config;
use Ynamite\Media\Glide\CacheKeyBuilder;
use Ynamite\Media\Glide\Server;
use Ynamite\Media\Glide\Signature;

final class UrlBuilder
{
    /**
     * Build a URL for a single variant of a resolved image.
     *
     * Glide params (w/h/q/fm/fit) are encoded into the cache path itself.
     * Filter params ride in a separate `&f=base64url(json)` query parameter
     * because their values can include special characters and the cache key
     * only carries an 8-char hash for unambiguity. HMAC covers `path|f`
     * together when filters are present.
     *
     * @param array<string, scalar> $filterParams Glide-keyed filter params.
     */
    public function build(
        ResolvedImage $image,
        int $width,
        string $format,
        ?int $quality = null,
        ?int $height = null,
        ?string $fitToken = null,
        array $filterParams = [],
    ): string {
        $quality ??= Config::quality($format);

        if (Config::cdnEnabled()) {
            return $this->buildCdnUrl($image, $width, $format, $quality, $height, $fitToken, $filterParams);
        }

        $cachePath = Server::cachePath($image->sourcePath, [
            'fm' => $format,
            'w' => $width,
            'q' => $quality,
            'h' => $height,
            'fit' => $fitToken,
            'filters' => $filterParams,
        ]);

        $filterBlob = $filterParams !== [] ? CacheKeyBuilder::encodeFilterBlob($filterParams) : '';

        $signature = Signature::sign($cachePath, $filterBlob !== '' ? $filterBlob : null);

        $url = rex_url::addonAssets(Config::ADDON, 'cache/' . $cachePath);
        $url .= '?s=' . $signature;
        if ($image->mtime > 0) {
            $url .= '&v=' . $image->mtime;
        }
        if ($filterBlob !== '') {
            $url .= '&f=' . $filterBlob;
        }
        return $url;
    }

    /**
     * Build a signed URL for the animated WebP variant of an animated source.
     * Returns '' when CDN mode is on (the CDN doesn't run our encoder) or when
     * the source isn't actually animated. Caller falls back to the GIF in
     * either case.
     */
    public function buildAnimatedWebp(ResolvedImage $image): string
    {
        // Mirror AnimatedWebpEncoder::shouldEncode — only animated GIFs in
        // self-served (non-CDN) mode get the WebP wrap. Anything else returns
        // '' and the caller falls back to the plain <img>.
        if (!$image->isAnimated || $image->sourceFormat !== 'gif' || Config::cdnEnabled()) {
            return '';
        }
        $cachePath = AnimatedWebpEncoder::cacheRelPath($image->sourcePath);
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
     * Template tokens: {w}, {h}, {q}, {fm}, {fit}, {src}, {f}.
     * Existing templates without {h}/{fit}/{f} keep emitting the same URLs.
     */
    private function buildCdnUrl(
        ResolvedImage $image,
        int $width,
        string $format,
        int $quality,
        ?int $height,
        ?string $fitToken,
        array $filterParams,
    ): string {
        $template = Config::cdnUrlTemplate();
        if ($template === '') {
            $template = '{src}?w={w}&q={q}&fm={fm}';
        }

        $filterBlob = $filterParams !== [] ? CacheKeyBuilder::encodeFilterBlob($filterParams) : '';

        $expanded = strtr($template, [
            '{w}' => (string) $width,
            '{q}' => (string) $quality,
            '{fm}' => $format,
            '{src}' => $image->sourcePath,
            '{h}' => $height !== null ? (string) $height : '',
            '{fit}' => $fitToken ?? '',
            '{f}' => $filterBlob,
        ]);

        $base = Config::cdnBase();
        return $base . '/' . ltrim($expanded, '/');
    }

}
