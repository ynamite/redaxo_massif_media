<?php

declare(strict_types=1);

namespace Ynamite\Media;

use rex_media;
use Throwable;
use Ynamite\Media\Builder\ImageBuilder;
use Ynamite\Media\Enum\Decoding;
use Ynamite\Media\Enum\FetchPriority;
use Ynamite\Media\Enum\Fit;
use Ynamite\Media\Enum\Loading;
use Ynamite\Media\Pipeline\ImageResolver;
use Ynamite\Media\Pipeline\MetadataReader;

class Image
{
    /**
     * The 80% case: render a <picture> with named arguments.
     *
     * For complex cases (focal override, preload, custom widths/quality, blurhash attr),
     * use Image::for($src)->...->render() instead.
     */
    public static function picture(
        string|rex_media $src,
        ?string $alt = null,
        ?int $width = null,
        ?int $height = null,
        ?float $ratio = null,
        ?string $sizes = null,
        Loading|string $loading = Loading::LAZY,
        Decoding|string $decoding = Decoding::ASYNC,
        FetchPriority|string $fetchPriority = FetchPriority::AUTO,
        ?string $focal = null,
        bool $preload = false,
        ?string $class = null,
        Fit|string|null $fit = null,
        array $filters = [],
    ): string {
        $b = self::for($src);
        if ($alt !== null) {
            $b->alt($alt);
        }
        if ($width !== null) {
            $b->width($width);
        }
        if ($height !== null) {
            $b->height($height);
        }
        if ($ratio !== null) {
            $b->ratio($ratio);
        }
        if ($sizes !== null) {
            $b->sizes($sizes);
        }
        $b->loading($loading)->decoding($decoding)->fetchPriority($fetchPriority);
        if ($focal !== null) {
            $b->focal($focal);
        }
        if ($preload) {
            $b->preload();
        }
        if ($class !== null) {
            $b->class($class);
        }
        if ($fit !== null) {
            $b->fit($fit);
        }
        if ($filters !== []) {
            $b->filters($filters);
        }
        return $b->render();
    }

    /**
     * Start a fluent builder. For complex cases or chained composition.
     */
    public static function for(string|rex_media $src): ImageBuilder
    {
        return new ImageBuilder($src);
    }

    /**
     * Return the cached blurhash string for an asset, or null if unavailable.
     * Useful for client-side galleries and JSON APIs.
     */
    public static function blurhash(string|rex_media $src): ?string
    {
        try {
            $resolver = new ImageResolver(new MetadataReader());
            return $resolver->resolve($src)->blurhash;
        } catch (Throwable) {
            return null;
        }
    }
}
