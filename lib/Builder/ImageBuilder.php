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
    private bool $withBlurhashAttr = false;
    private ?string $class = null;

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

    public function withBlurhashAttr(bool $on = true): self
    {
        $this->withBlurhashAttr = $on;
        return $this;
    }

    public function class(string $class): self
    {
        $this->class = $class;
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
            $this->withBlurhashAttr,
            $this->class,
            $this->fit,
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
            blurhash: $image->blurhash,
            mtime: $image->mtime,
        );
    }
}
