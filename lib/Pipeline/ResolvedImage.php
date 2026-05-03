<?php

declare(strict_types=1);

namespace Ynamite\Media\Pipeline;

final class ResolvedImage
{
    public function __construct(
        public readonly string $sourcePath,
        public readonly string $absolutePath,
        public readonly int $intrinsicWidth,
        public readonly int $intrinsicHeight,
        public readonly string $mime,
        public readonly string $sourceFormat,
        public readonly ?string $focalPoint = null,
        public readonly int $mtime = 0,
        public readonly bool $isAnimated = false,
    ) {
    }

    public function isPassthrough(): bool
    {
        return in_array($this->sourceFormat, ['svg', 'gif'], true);
    }

    public function aspectRatio(): float
    {
        return $this->intrinsicHeight > 0
            ? $this->intrinsicWidth / $this->intrinsicHeight
            : 0.0;
    }
}
