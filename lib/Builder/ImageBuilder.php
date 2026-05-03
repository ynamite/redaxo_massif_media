<?php

declare(strict_types=1);

namespace Ynamite\Media\Builder;

use rex_logger;
use rex_media;
use Throwable;
use Ynamite\Media\Enum\Decoding;
use Ynamite\Media\Enum\FetchPriority;
use Ynamite\Media\Enum\Fit;
use Ynamite\Media\Enum\Loading;
use Ynamite\Media\Exception\ImageNotFoundException;
use Ynamite\Media\Glide\FilterParams;
use Ynamite\Media\Pipeline\ImageResolver;
use Ynamite\Media\Pipeline\MetadataReader;
use Ynamite\Media\Pipeline\Placeholder;
use Ynamite\Media\Pipeline\Preloader;
use Ynamite\Media\Pipeline\ResolvedImage;
use Ynamite\Media\Pipeline\SrcsetBuilder;
use Ynamite\Media\Pipeline\UrlBuilder;
use Ynamite\Media\View\PassthroughRenderer;
use Ynamite\Media\View\PictureRenderer;

final class ImageBuilder
{
    private string|rex_media $src;
    private ?string $alt = null;
    private ?int $width = null;
    private ?int $height = null;
    private ?float $ratio = null;
    private ?string $sizes = null;
    private ?array $widthsOverride = null;
    private ?array $formatsOverride = null;
    private ?array $qualityOverride = null;
    private Loading $loading = Loading::LAZY;
    private Decoding $decoding = Decoding::ASYNC;
    private FetchPriority $fetchPriority = FetchPriority::AUTO;
    private ?Fit $fit = null;
    private bool $preload = false;
    private ?string $focal = null;
    private ?string $class = null;
    /** @var array<string, scalar> Glide-keyed filter params. */
    private array $filterParams = [];

    public function __construct(string|rex_media $src)
    {
        $this->src = $src;
    }

    public function alt(string $alt): self
    {
        $this->alt = $alt;
        return $this;
    }

    public function width(int $width): self
    {
        $this->width = $width;
        return $this;
    }

    public function height(int $height): self
    {
        $this->height = $height;
        return $this;
    }

    /**
     * Set aspect ratio. Accepts either ratio(16, 9) or ratio(16 / 9).
     */
    public function ratio(int|float $w, ?int $h = null): self
    {
        $this->ratio = $h !== null && $h > 0 ? ((float) $w) / ((float) $h) : (float) $w;
        return $this;
    }

    public function sizes(string $sizes): self
    {
        $this->sizes = $sizes;
        return $this;
    }

    /**
     * @param int[] $widths
     */
    public function widths(array $widths): self
    {
        $this->widthsOverride = $widths;
        return $this;
    }

    /**
     * @param string[] $formats
     */
    public function formats(array $formats): self
    {
        $this->formatsOverride = $formats;
        return $this;
    }

    /**
     * @param int|array<string,int> $quality
     */
    public function quality(int|array $quality): self
    {
        $this->qualityOverride = is_int($quality)
            ? ['avif' => $quality, 'webp' => $quality, 'jpg' => $quality]
            : $quality;
        return $this;
    }

    public function loading(Loading|string $loading): self
    {
        $this->loading = is_string($loading) ? Loading::from($loading) : $loading;
        return $this;
    }

    public function decoding(Decoding|string $decoding): self
    {
        $this->decoding = is_string($decoding) ? Decoding::from($decoding) : $decoding;
        return $this;
    }

    public function fetchPriority(FetchPriority|string $priority): self
    {
        $this->fetchPriority = is_string($priority) ? FetchPriority::from($priority) : $priority;
        return $this;
    }

    public function fit(Fit|string $fit): self
    {
        $this->fit = is_string($fit) ? Fit::from($fit) : $fit;
        return $this;
    }

    public function preload(bool $on = true): self
    {
        $this->preload = $on;
        return $this;
    }

    public function focal(string $focal): self
    {
        $this->focal = $focal;
        return $this;
    }

    public function class(string $class): self
    {
        $this->class = $class;
        return $this;
    }

    public function brightness(int $value): self
    {
        $this->filterParams['bri'] = (int) FilterParams::clamp('bri', $value);
        return $this;
    }

    public function contrast(int $value): self
    {
        $this->filterParams['con'] = (int) FilterParams::clamp('con', $value);
        return $this;
    }

    public function gamma(float $value): self
    {
        $this->filterParams['gam'] = (float) FilterParams::clamp('gam', $value);
        return $this;
    }

    public function sharpen(int $value): self
    {
        $this->filterParams['sharp'] = (int) FilterParams::clamp('sharp', $value);
        return $this;
    }

    public function blur(int $value): self
    {
        $this->filterParams['blur'] = (int) FilterParams::clamp('blur', $value);
        return $this;
    }

    public function pixelate(int $value): self
    {
        $this->filterParams['pixel'] = (int) FilterParams::clamp('pixel', $value);
        return $this;
    }

    public function filter(string $preset): self
    {
        $this->filterParams['filt'] = $preset;
        return $this;
    }

    public function bg(string $hex): self
    {
        $validated = FilterParams::validateHex($hex);
        if ($validated !== null) {
            $this->filterParams['bg'] = strtolower($validated);
        }
        return $this;
    }

    public function border(int $width, string $color, string $method = 'overlay'): self
    {
        $this->filterParams['border'] = sprintf('%d,%s,%s', $width, $color, $method);
        return $this;
    }

    public function flip(string $axis): self
    {
        $this->filterParams['flip'] = $axis;
        return $this;
    }

    public function orient(int|string $value): self
    {
        $this->filterParams['orient'] = (string) $value;
        return $this;
    }

    public function watermark(
        string $src,
        ?float $size = null,
        ?int $width = null,
        ?int $height = null,
        string $position = 'center',
        int $padding = 0,
        int $alpha = 100,
        string $fit = 'contain',
    ): self {
        $this->filterParams['mark'] = $src;
        if ($size !== null) {
            $this->filterParams['marks'] = (float) FilterParams::clamp('marks', $size);
        }
        if ($width !== null) {
            $this->filterParams['markw'] = $width;
        }
        if ($height !== null) {
            $this->filterParams['markh'] = $height;
        }
        $this->filterParams['markpos'] = $position;
        $this->filterParams['markpad'] = max(0, $padding);
        $this->filterParams['markalpha'] = (int) FilterParams::clamp('markalpha', $alpha);
        $this->filterParams['markfit'] = $fit;
        return $this;
    }

    /**
     * Bulk-apply filters from a friendly-keyed array. Translates / clamps /
     * drops via FilterParams::normalize. Subsequent setter calls override.
     *
     * @param array<string, scalar> $filters
     */
    public function filters(array $filters): self
    {
        $normalized = FilterParams::normalize($filters);
        $this->filterParams = array_merge($this->filterParams, $normalized);
        return $this;
    }

    public function render(): string
    {
        $resolver = new ImageResolver(new MetadataReader());
        try {
            $image = $resolver->resolve($this->src);
        } catch (ImageNotFoundException $e) {
            rex_logger::logException($e);
            return '';
        }

        if ($this->focal !== null) {
            $image = $this->withFocal($image, $this->focal);
        }

        if ($image->isPassthrough()) {
            return (new PassthroughRenderer())->render(
                $image,
                $this->width,
                $this->height,
                $this->ratio,
                $this->alt,
                $this->loading,
                $this->decoding,
                $this->class,
            );
        }

        if ($this->preload) {
            Preloader::queue(
                $image,
                $this->width,
                $this->height,
                $this->ratio,
                $this->sizes,
                $this->widthsOverride,
                $this->formatsOverride,
                $this->qualityOverride,
                $this->fit,
                $this->filterParams,
            );
        }

        return (new PictureRenderer(
            new SrcsetBuilder(),
            new UrlBuilder(),
            new Placeholder(),
        ))->render(
            $image,
            $this->width,
            $this->height,
            $this->ratio,
            $this->alt,
            $this->sizes,
            $this->widthsOverride,
            $this->formatsOverride,
            $this->qualityOverride,
            $this->loading,
            $this->decoding,
            $this->fetchPriority,
            $this->class,
            $this->fit,
            $this->filterParams,
        );
    }

    public function __toString(): string
    {
        try {
            return $this->render();
        } catch (Throwable $e) {
            rex_logger::logException($e);
            return '';
        }
    }

    private function withFocal(ResolvedImage $image, string $focal): ResolvedImage
    {
        return new ResolvedImage(
            sourcePath: $image->sourcePath,
            absolutePath: $image->absolutePath,
            intrinsicWidth: $image->intrinsicWidth,
            intrinsicHeight: $image->intrinsicHeight,
            mime: $image->mime,
            sourceFormat: $image->sourceFormat,
            focalPoint: $focal,
            mtime: $image->mtime,
        );
    }
}
