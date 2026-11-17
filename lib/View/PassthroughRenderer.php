<?php

declare(strict_types=1);

namespace Ynamite\Media\View;

use Ynamite\Media\Config;
use Ynamite\Media\Enum\Decoding;
use Ynamite\Media\Enum\FetchPriority;
use Ynamite\Media\Enum\Loading;
use Ynamite\Media\Pipeline\ResolvedImage;
use Ynamite\Media\Pipeline\UrlBuilder;

final class PassthroughRenderer
{
    /**
     * UrlBuilder is optional so existing callers that don't care about the
     * animated-WebP wrap (eg. simple test setups) can skip it. When omitted,
     * even animated sources fall through to the plain <img> path.
     */
    public function __construct(private ?UrlBuilder $urlBuilder = null)
    {
    }

    /**
     * Keep original bytes in the fallback <img>. Encodable mediapool GIF/WebP
     * animations get a responsive WebP source before it.
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
        ?string $sizes = null,
        FetchPriority $fetchPriority = FetchPriority::AUTO,
    ): string {
        $w = $width ?? $image->intrinsicWidth;
        $ratio = $ratio !== null && $ratio > 0 ? $ratio : $image->aspectRatio();
        $h = $height ?? ($ratio > 0
            ? (int) round($w / $ratio)
            : $image->intrinsicHeight);

        $imgTag = $this->buildImgTag($image, $w, $h, $alt, $loading, $decoding, $class, $fetchPriority);

        $srcset = $this->urlBuilder?->buildAnimatedSrcset($image) ?? '';
        if ($srcset === '') {
            return $imgTag;
        }

        return '<picture>'
            . '<source type="image/webp" srcset="'
            . htmlspecialchars($srcset, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '" sizes="'
            . htmlspecialchars($sizes ?? Config::defaultSizes(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
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
        FetchPriority $fetchPriority,
    ): string {
        $attrs = [
            'src' => UrlBuilder::originalUrl($image),
            'width' => (string) $w,
            'height' => (string) $h,
            'alt' => $alt ?? '',
            'loading' => $loading->value,
            'decoding' => $decoding->value,
        ];
        if ($fetchPriority !== FetchPriority::AUTO) {
            $attrs['fetchpriority'] = $fetchPriority->value;
        }
        if ($alt === null || $alt === '') {
            $attrs['aria-hidden'] = 'true';
        }
        if ($class !== null && $class !== '') {
            $attrs['class'] = $class;
        }
        if ($image->focalPoint !== null) {
            $attrs['style'] = 'object-position:' . $image->focalPoint;
        }

        $tag = '<img';
        foreach ($attrs as $name => $value) {
            $tag .= ' ' . $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        return $tag . '>';
    }
}
