<?php

declare(strict_types=1);

namespace Ynamite\Media\View;

use Ynamite\Media\Config;
use Ynamite\Media\Enum\Decoding;
use Ynamite\Media\Enum\FetchPriority;
use Ynamite\Media\Enum\Fit;
use Ynamite\Media\Enum\Loading;
use Ynamite\Media\Pipeline\DominantColor;
use Ynamite\Media\Pipeline\Placeholder;
use Ynamite\Media\Pipeline\RenderContext;
use Ynamite\Media\Pipeline\ResolvedImage;
use Ynamite\Media\Pipeline\SrcsetBuilder;
use Ynamite\Media\Pipeline\UrlBuilder;

final class PictureRenderer
{
    public function __construct(
        private SrcsetBuilder $srcsetBuilder,
        private UrlBuilder $urlBuilder,
        private Placeholder $placeholder,
        private DominantColor $dominantColor,
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
        ?string $class = null,
        ?Fit $fit = null,
        array $filterParams = [],
    ): string {
        $sizes ??= Config::defaultSizes();
        $formats = $this->normalizeFormats($formats ?? Config::formats());

        $ctx = RenderContext::build(
            image: $image,
            width: $width,
            height: $height,
            ratio: $ratio,
            fit: $fit,
            widthsOverride: $widthsOverride,
            srcsetBuilder: $this->srcsetBuilder,
        );
        if ($ctx->widths === []) {
            return '';
        }

        $fallbackFormat = end($formats) ?: 'jpg';

        $sources = [];
        foreach ($formats as $fmt) {
            if ($fmt === $fallbackFormat) {
                continue;
            }
            $quality = $qualityOverride[$fmt] ?? null;
            $srcset = $ctx->buildSrcset($this->urlBuilder, $image, $fmt, $quality, $filterParams);
            $sources[] = sprintf(
                '<source type="image/%s" srcset="%s" sizes="%s">',
                self::escape($this->mimeSubtype($fmt)),
                self::escape($srcset),
                self::escape($sizes),
            );
        }

        $fallbackQuality = $qualityOverride[$fallbackFormat] ?? null;
        $fallbackSrcset = $ctx->buildSrcset($this->urlBuilder, $image, $fallbackFormat, $fallbackQuality, $filterParams);

        $midIdx = (int) floor((count($ctx->widths) - 1) / 2);
        $midWidth = $ctx->widths[$midIdx];
        $midHeight = ($ctx->effectiveRatio !== null && $ctx->fitToken !== null)
            ? (int) round($midWidth / $ctx->effectiveRatio)
            : null;
        $fallbackSrc = $this->urlBuilder->build($image, $midWidth, $fallbackFormat, $fallbackQuality, $midHeight, $ctx->fitToken, $filterParams);

        [$attrW, $attrH] = $this->computeIntrinsicAttrs($image, $width, $height, $ctx->effectiveRatio);

        $lqip = $this->placeholder->generate($image);
        $color = $this->dominantColor->generate($image);

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

        // Style attr order matters: background-color first so it paints
        // immediately, LQIP background-image overlays it once decoded, focal
        // object-position positions the loaded raster.
        $style = [];
        if ($color !== '') {
            $style[] = 'background-color:' . $color;
        }
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
