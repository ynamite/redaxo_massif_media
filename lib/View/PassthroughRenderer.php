<?php

declare(strict_types=1);

namespace Ynamite\Media\View;

use rex_url;
use Ynamite\Media\Enum\Decoding;
use Ynamite\Media\Enum\Loading;
use Ynamite\Media\Pipeline\ResolvedImage;

final class PassthroughRenderer
{
    /**
     * Emit a plain <img> for sources that cannot be safely rasterized
     * (svg, gif, or unknown formats). No srcset, no resizing.
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
