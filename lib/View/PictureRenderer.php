<?php

declare(strict_types=1);

namespace Ynamite\Media\View;

use Ynamite\Media\Config;
use Ynamite\Media\Enum\Decoding;
use Ynamite\Media\Enum\FetchPriority;
use Ynamite\Media\Enum\Loading;
use Ynamite\Media\Pipeline\Placeholder;
use Ynamite\Media\Pipeline\ResolvedImage;
use Ynamite\Media\Pipeline\SrcsetBuilder;
use Ynamite\Media\Pipeline\UrlBuilder;

final class PictureRenderer
{
    public function __construct(
        private SrcsetBuilder $srcsetBuilder,
        private UrlBuilder $urlBuilder,
        private Placeholder $placeholder,
    ) {
    }

    /**
     * Render a full <picture><source><img></picture> for a raster source.
     *
     * @param int[]|null              $widthsOverride  Optional explicit widths (replaces default pool).
     * @param string[]|null           $formats         e.g. ['avif','webp','jpg']; the LAST is the fallback.
     * @param array<string,int>|null  $qualityOverride Per-format quality overrides.
     */
    public function render(
        ResolvedImage $image,
        ?int $width = null,
        ?int $height = null,
        ?float $ratio = null,
        ?string $alt = null,
        ?string $sizes = null,
        ?array $widthsOverride = null,
        ?array $formats = null,
        ?array $qualityOverride = null,
        Loading $loading = Loading::LAZY,
        Decoding $decoding = Decoding::ASYNC,
        FetchPriority $fetchPriority = FetchPriority::AUTO,
        bool $withBlurhashAttr = false,
        ?string $class = null,
    ): string {
        $sizes ??= Config::defaultSizes();
        $formats = $this->normalizeFormats($formats ?? Config::formats());

        $widths = $this->srcsetBuilder->build($image->intrinsicWidth, $width, $widthsOverride);
        if ($widths === []) {
            return '';
        }

        $fallbackFormat = end($formats) ?: 'jpg';

        $sources = [];
        foreach ($formats as $fmt) {
            if ($fmt === $fallbackFormat) {
                continue;
            }
            $quality = $qualityOverride[$fmt] ?? null;
            $srcset = $this->buildSrcset($image, $widths, $fmt, $quality);
            $sources[] = sprintf(
                '<source type="image/%s" srcset="%s" sizes="%s">',
                self::escape($this->mimeSubtype($fmt)),
                self::escape($srcset),
                self::escape($sizes),
            );
        }

        $fallbackQuality = $qualityOverride[$fallbackFormat] ?? null;
        $fallbackSrcset = $this->buildSrcset($image, $widths, $fallbackFormat, $fallbackQuality);

        // src points at a mid-pool variant; browsers without srcset support pick this.
        $midIdx = (int) floor((count($widths) - 1) / 2);
        $fallbackSrc = $this->urlBuilder->build($image, $widths[$midIdx], $fallbackFormat, $fallbackQuality);

        [$attrW, $attrH] = $this->computeIntrinsicAttrs($image, $width, $height, $ratio);

        $lqip = $this->placeholder->generate($image);

        $imgAttrs = [
            'src' => $fallbackSrc,
            'srcset' => $fallbackSrcset,
            'sizes' => $sizes,
            'width' => (string) $attrW,
            'height' => (string) $attrH,
            'alt' => $alt ?? '',
            'loading' => $loading->value,
            'decoding' => $decoding->value,
        ];
        if ($fetchPriority !== FetchPriority::AUTO) {
            $imgAttrs['fetchpriority'] = $fetchPriority->value;
        }
        if ($alt === null || $alt === '') {
            $imgAttrs['aria-hidden'] = 'true';
        }
        if ($class !== null && $class !== '') {
            $imgAttrs['class'] = $class;
        }
        if ($withBlurhashAttr && $image->blurhash !== null) {
            $imgAttrs['data-blurhash'] = $image->blurhash;
        }

        $style = [];
        if ($lqip !== '') {
            $style[] = 'background-size:cover';
            $style[] = "background-image:url('" . str_replace("'", "\\'", $lqip) . "')";
        }
        if ($image->focalPoint !== null) {
            $style[] = 'object-position:' . $image->focalPoint;
        }
        if ($style !== []) {
            $imgAttrs['style'] = implode(';', $style);
        }

        return '<picture>' . implode('', $sources) . $this->renderImg($imgAttrs) . '</picture>';
    }

    private function buildSrcset(ResolvedImage $image, array $widths, string $format, ?int $quality): string
    {
        $entries = [];
        foreach ($widths as $w) {
            $url = $this->urlBuilder->build($image, $w, $format, $quality);
            $entries[] = $url . ' ' . $w . 'w';
        }
        return implode(', ', $entries);
    }

    /**
     * @param array<string,string> $attrs
     */
    private function renderImg(array $attrs): string
    {
        $tag = '<img';
        foreach ($attrs as $name => $value) {
            $tag .= ' ' . $name . '="' . self::escape($value) . '"';
        }
        return $tag . '>';
    }

    /**
     * @param string[] $formats
     * @return string[]
     */
    private function normalizeFormats(array $formats): array
    {
        $out = [];
        foreach ($formats as $f) {
            $f = strtolower((string) $f);
            if ($f !== '' && !in_array($f, $out, true)) {
                $out[] = $f;
            }
        }
        return $out;
    }

    private function mimeSubtype(string $fmt): string
    {
        return $fmt === 'jpg' ? 'jpeg' : $fmt;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function computeIntrinsicAttrs(
        ResolvedImage $image,
        ?int $width,
        ?int $height,
        ?float $ratio,
    ): array {
        $w = $width ?? $image->intrinsicWidth;
        if ($height !== null) {
            return [$w, $height];
        }
        if ($ratio !== null && $ratio > 0) {
            return [$w, (int) round($w / $ratio)];
        }
        $imageRatio = $image->aspectRatio();
        if ($imageRatio > 0) {
            return [$w, (int) round($w / $imageRatio)];
        }
        return [$w, $image->intrinsicHeight];
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
