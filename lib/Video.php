<?php

declare(strict_types=1);

namespace Ynamite\Media;

use rex_media;
use Ynamite\Media\Builder\VideoBuilder;
use Ynamite\Media\Enum\Loading;

class Video
{
    /**
     * Render a <video> element with the most common params. The 80% case.
     *
     * For complex composition, use Video::for($src)->...->render() instead.
     */
    public static function render(
        string|rex_media $src,
        ?string $poster = null,
        ?int $width = null,
        ?int $height = null,
        ?string $alt = null,
        bool $autoplay = false,
        bool $muted = false,
        bool $loop = false,
        bool $controls = true,
        bool $playsinline = true,
        string $preload = 'metadata',
        Loading|string $loading = Loading::LAZY,
        ?string $class = null,
    ): string {
        $b = self::for($src);
        if ($poster !== null) {
            $b->poster($poster);
        }
        if ($width !== null) {
            $b->width($width);
        }
        if ($height !== null) {
            $b->height($height);
        }
        if ($alt !== null) {
            $b->alt($alt);
        }
        if ($autoplay) {
            $b->autoplay();
        }
        if ($muted) {
            $b->muted();
        }
        if ($loop) {
            $b->loop();
        }
        $b->controls($controls)->playsinline($playsinline)->preload($preload)->loading($loading);
        if ($class !== null) {
            $b->class($class);
        }
        return $b->render();
    }

    /**
     * Start a fluent builder for a Video.
     */
    public static function for(string|rex_media $src): VideoBuilder
    {
        return new VideoBuilder($src);
    }
}
