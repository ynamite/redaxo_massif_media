<?php

declare(strict_types=1);

namespace Ynamite\Media\View;

use rex_url;
use Ynamite\Media\Enum\Decoding;
use Ynamite\Media\Enum\Loading;
use Ynamite\Media\Pipeline\ResolvedImage;
use Ynamite\Media\Pipeline\UrlBuilder;

final class PassthroughRenderer
{
    /**
     * UrlBuilder is optional so existing callers that don't care about the
     * animated-WebP wrap (eg. simple test setups) can skip it. When omitted,
     * even animated GIFs fall through to the plain <img> path.
     */
    public function __construct(private ?UrlBuilder $urlBuilder = null)
    {
    }

    /**
     * Emit markup for sources that cannot go through the regular Glide
     * resize pipeline (svg, gif, or unknown formats).
     *
     *   - SVG / static GIF / unknown:  plain <img> at intrinsic dims.
     *   - Animated GIF:                <picture> with <source type="image/webp"
     *                                  srcset="…/animated.webp"> + <img> GIF
     *                                  fallback. Modern browsers fetch the
     *                                  WebP (typ. 40-60% smaller); older
     *                                  browsers and CDN-mode installs fall
     *                                  through to the GIF.
     */
    public function render(
        ResolvedImage $image,
        ?int $width = null,
        ?int $height = null,
        ?float $ratio = null,
        ?string $alt = null,
        Loading $loading = Loading::LAZY,
        Decoding $decoding = Decoding::ASYNC,
        ?string $class = null,
    ): string {
        $w = $width ?? $image->intrinsicWidth;
        $h = $height ?? ($ratio !== null && $ratio > 0
            ? (int) round($w / $ratio)
            : $image->intrinsicHeight);

        $imgTag = $this->buildImgTag($image, $w, $h, $alt, $loading, $decoding, $class);

        $animatedWebpUrl = $image->isAnimated && $this->urlBuilder !== null
            ? $this->urlBuilder->buildAnimatedWebp($image)
            : '';
        if ($animatedWebpUrl === '') {
            return $imgTag;
        }

        return '<picture>'
            . '<source type="image/webp" srcset="'
            . htmlspecialchars($animatedWebpUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '">'
            . $imgTag
            . '</picture>';
    }

    private function buildImgTag(
        ResolvedImage $image,
        int $w,
        int $h,
        ?string $alt,
        Loading $loading,
        Decoding $decoding,
        ?string $class,
    ): string {
        $attrs = [
            'src' => rex_url::base() . 'media/' . $image->sourcePath,
            'width' => (string) $w,
            'height' => (string) $h,
            'alt' => $alt ?? '',
            'loading' => $loading->value,
            'decoding' => $decoding->value,
        ];
        if ($alt === null || $alt === '') {
            $attrs['aria-hidden'] = 'true';
        }
        if ($class !== null && $class !== '') {
            $attrs['class'] = $class;
        }

        $tag = '<img';
        foreach ($attrs as $name => $value) {
            $tag .= ' ' . $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        return $tag . '>';
    }
}
