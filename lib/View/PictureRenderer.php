<?php

declare(strict_types=1);

namespace Ynamite\Media\View;

use Ynamite\Media\Config;
use Ynamite\Media\Enum\Decoding;
use Ynamite\Media\Enum\FetchPriority;
use Ynamite\Media\Enum\Fit;
use Ynamite\Media\Enum\Loading;
use Ynamite\Media\Pipeline\Placeholder;
use Ynamite\Media\Pipeline\ResolvedImage;
use Ynamite\Media\Pipeline\SrcsetBuilder;
use Ynamite\Media\Pipeline\UrlBuilder;

final class PictureRenderer
{
    private const RATIO_EQUAL_EPSILON = 0.001;

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
        ?Fit $fit = null,
    ): string {
        $sizes ??= Config::defaultSizes();
        $formats = $this->normalizeFormats($formats ?? Config::formats());

        // Resolve effective ratio: explicit > derived from width+height > null.
        $effectiveRatio = $ratio;
        if ($effectiveRatio === null && $height !== null && $height > 0 && $width !== null && $width > 0) {
            $effectiveRatio = $width / $height;
        }

        // Resolve effective fit. Default depends on whether a target box is set.
        $effectiveFit = $fit ?? ($effectiveRatio !== null ? Fit::COVER : Fit::NONE);

        // Decide whether we actually need to crop for this render. Skip when:
        // - fit is NONE (caller opted out), or
        // - no target box (no ratio derived), or
        // - ratio matches intrinsic within epsilon (no point cropping the same shape).
        $intrinsicRatio = $image->aspectRatio();
        $needsCrop = $effectiveFit !== Fit::NONE
            && $effectiveRatio !== null
            && $intrinsicRatio > 0
            && abs($effectiveRatio - $intrinsicRatio) > self::RATIO_EQUAL_EPSILON;

        $fitToken = null;
        $effectiveMaxWidth = null;
        if ($needsCrop) {
            $fitToken = $this->buildFitToken($effectiveFit, $image->focalPoint);
            // For cover/contain, never ask Glide to upscale beyond what the source
            // can deliver after cropping. Stretch is exempt — it can squish to any size.
            if ($effectiveFit !== Fit::STRETCH && $effectiveRatio > 0) {
                $effectiveMaxWidth = (int) min(
                    $image->intrinsicWidth,
                    (int) floor($image->intrinsicHeight * $effectiveRatio),
                );
            }
        }

        $widths = $this->srcsetBuilder->build($image->intrinsicWidth, $widthsOverride, $effectiveMaxWidth);
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
            $srcset = $this->buildSrcset($image, $widths, $fmt, $quality, $effectiveRatio, $fitToken);
            $sources[] = sprintf(
                '<source type="image/%s" srcset="%s" sizes="%s">',
                self::escape($this->mimeSubtype($fmt)),
                self::escape($srcset),
                self::escape($sizes),
            );
        }

        $fallbackQuality = $qualityOverride[$fallbackFormat] ?? null;
        $fallbackSrcset = $this->buildSrcset($image, $widths, $fallbackFormat, $fallbackQuality, $effectiveRatio, $fitToken);

        $midIdx = (int) floor((count($widths) - 1) / 2);
        $midWidth = $widths[$midIdx];
        $midHeight = ($effectiveRatio !== null && $fitToken !== null)
            ? (int) round($midWidth / $effectiveRatio)
            : null;
        $fallbackSrc = $this->urlBuilder->build($image, $midWidth, $fallbackFormat, $fallbackQuality, $midHeight, $fitToken);

        [$attrW, $attrH] = $this->computeIntrinsicAttrs($image, $width, $height, $effectiveRatio);

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

    private function buildSrcset(
        ResolvedImage $image,
        array $widths,
        string $format,
        ?int $quality,
        ?float $effectiveRatio,
        ?string $fitToken,
    ): string {
        $entries = [];
        foreach ($widths as $w) {
            $h = ($effectiveRatio !== null && $fitToken !== null) ? (int) round($w / $effectiveRatio) : null;
            $url = $this->urlBuilder->build($image, $w, $format, $quality, $h, $fitToken);
            $entries[] = $url . ' ' . $w . 'w';
        }
        return implode(', ', $entries);
    }

    private function buildFitToken(Fit $fit, ?string $focalPoint): string
    {
        if ($fit !== Fit::COVER) {
            return $fit->value;  // 'contain' or 'stretch'
        }

        // Cover requires focal coordinates as integers (Glide's `crop-X-Y` regex
        // rejects decimals on the first two coords — vendor/league/glide/src/Manipulators/Size.php:118).
        [$fx, $fy] = $this->parseFocalToInts($focalPoint);
        return sprintf('cover-%d-%d', $fx, $fy);
    }

    /**
     * Parse "X% Y%" → [int, int] clamped to 0-100. Falls back to [50, 50] on
     * unparseable / missing input. MetadataReader::normalizeFocal already
     * produces this format from any focuspoint addon variant.
     *
     * @return array{0: int, 1: int}
     */
    private function parseFocalToInts(?string $value): array
    {
        if ($value === null || !preg_match('/^([\d.]+)%\s+([\d.]+)%$/', $value, $m)) {
            return [50, 50];
        }
        $x = max(0, min(100, (int) round((float) $m[1])));
        $y = max(0, min(100, (int) round((float) $m[2])));
        return [$x, $y];
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
