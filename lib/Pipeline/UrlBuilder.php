<?php

declare(strict_types=1);

namespace Ynamite\Media\Pipeline;

use rex_url;
use Ynamite\Media\Config;
use Ynamite\Media\Glide\CacheKeyBuilder;
use Ynamite\Media\Glide\Server;
use Ynamite\Media\Glide\Signature;
use Ynamite\Media\Source\ExternalSource;

final class UrlBuilder
{
    /** Prefix a local URL, including REDAXO's relative backend paths. */
    public static function assetUrl(string $url): string
    {
        $host = Config::assetHost();
        return $host !== '' ? $host . '/' . ltrim($url, './') : $url;
    }

    public static function originalUrl(ResolvedImage $image): string
    {
        if ($image->source instanceof ExternalSource) {
            return $image->source->url;
        }

        $url = self::assetUrl(rex_url::base() . 'media/' . $image->source->key());
        $bust = $image->source->cacheBust();
        return $bust !== '' && $bust !== '0' ? $url . '?v=' . $bust : $url;
    }

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

        if (Config::cdnEnabled() && !$image->source->isExternal()) {
            return $this->buildCdnUrl($image, $width, $format, $quality, $height, $fitToken, $filterParams);
        }

        $cachePath = Server::cachePath($image->source->key(), [
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
        if (!$image->source->isExternal()) {
            $url = self::assetUrl($url);
        }
        $url .= '?s=' . $signature;
        $bust = $image->source->cacheBust();
        if ($bust !== '' && $bust !== '0') {
            $url .= '&v=' . $bust;
        }
        // Browser-cache-busting token. Outside the HMAC payload because
        // the on-disk cache path is `<src>/<spec>.<ext>` (independent of g);
        // changing g doesn't move the file, only invalidates the browser
        // cache after a server-side clear. See Config::cacheGeneration.
        $url .= '&g=' . Config::cacheGeneration();
        if ($filterBlob !== '') {
            $url .= '&f=' . $filterBlob;
        }
        return $url;
    }

    /**
     * Build a signed animated WebP URL. Sources outside the encoding budget,
     * external sources and CDN mode fall back to their original bytes.
     */
    public function buildAnimatedWebp(ResolvedImage $image, int $width): string
    {
        if (
            !$image->isAnimatedEncodable()
            || !Config::canServerEncode('webp')
            || Config::cdnEnabled()
            || $image->source->isExternal()
        ) {
            return '';
        }
        $cachePath = AnimatedWebpEncoder::cacheRelPath($image->source->key(), $width, $image->intrinsicWidth);
        $signature = Signature::sign($cachePath);
        $url = self::assetUrl(rex_url::addonAssets(Config::ADDON, 'cache/' . $cachePath));
        $url .= '?s=' . $signature;
        $bust = $image->source->cacheBust();
        if ($bust !== '' && $bust !== '0') {
            $url .= '&v=' . $bust;
        }
        $url .= '&g=' . Config::cacheGeneration();
        return $url;
    }

    public function buildAnimatedSrcset(ResolvedImage $image): string
    {
        $entries = [];
        foreach (AnimatedWebpEncoder::widths($image) as $width) {
            $url = $this->buildAnimatedWebp($image, $width);
            if ($url === '') {
                return '';
            }
            $entries[] = $url . ' ' . $width . 'w';
        }
        return implode(', ', $entries);
    }

    /**
     * Build a CDN URL using the configured base and template.
     *
     * Template tokens: {w}, {h}, {q}, {fm}, {fit}, {src}, {f}.
     * Existing templates without {h}/{fit}/{f} keep emitting the same URLs.
     *
     * Note: external-URL sources never go through the CDN — the upstream URL
     * is already its own CDN, and reformatting it through ours would defeat
     * the point. {@see UrlBuilder::build()} short-circuits the CDN branch
     * when the source is external.
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
            '{src}' => $image->source->key(),
            '{h}' => $height !== null ? (string) $height : '',
            '{fit}' => $fitToken ?? '',
            '{f}' => $filterBlob,
            '{g}' => (string) Config::cacheGeneration(),
        ]);

        $base = Config::cdnBase();
        return $base . '/' . ltrim($expanded, '/');
    }

}
