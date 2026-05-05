<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Helpers;

use Ynamite\Media\Pipeline\ResolvedImage;
use Ynamite\Media\Source\MediapoolSource;

/**
 * Test helpers — small ergonomic builders for the new SourceInterface-based
 * pipeline. Centralises the shape so individual tests don't carry parallel
 * `new MediapoolSource(...)` boilerplate.
 */
final class Sources
{
    public static function mediapool(
        string $filename = 'hero.jpg',
        ?string $absolutePath = null,
        int $mtime = 1_700_000_000,
    ): MediapoolSource {
        return new MediapoolSource(
            filename: $filename,
            absolutePath: $absolutePath ?? '/tmp/massif-stub/' . $filename,
            mtime: $mtime,
        );
    }

    public static function image(
        string $filename = 'hero.jpg',
        ?string $absolutePath = null,
        int $mtime = 1_700_000_000,
        int $intrinsicWidth = 1600,
        int $intrinsicHeight = 900,
        string $mime = 'image/jpeg',
        string $sourceFormat = 'jpg',
        ?string $focalPoint = null,
        bool $isAnimated = false,
    ): ResolvedImage {
        return new ResolvedImage(
            source: self::mediapool($filename, $absolutePath, $mtime),
            intrinsicWidth: $intrinsicWidth,
            intrinsicHeight: $intrinsicHeight,
            mime: $mime,
            sourceFormat: $sourceFormat,
            focalPoint: $focalPoint,
            isAnimated: $isAnimated,
        );
    }
}
