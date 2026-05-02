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

        $filterBlob = '';
        if ($filterParams !== []) {
            ksort($filterParams);
            $filterBlob = self::base64UrlEncode(json_encode($filterParams, JSON_FORCE_OBJECT));
        }

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

        $filterBlob = '';
        if ($filterParams !== []) {
            ksort($filterParams);
            $filterBlob = self::base64UrlEncode(json_encode($filterParams, JSON_FORCE_OBJECT));
        }

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

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string|false
    {
        return base64_decode(strtr($data, '-_', '+/'), true);
    }
}
