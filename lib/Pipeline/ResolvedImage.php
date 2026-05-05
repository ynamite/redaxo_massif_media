<?php

declare(strict_types=1);

namespace Ynamite\Media\Pipeline;

use Ynamite\Media\Source\SourceInterface;

final readonly class ResolvedImage
{
    public function __construct(
        public SourceInterface $source,
        public int $intrinsicWidth,
        public int $intrinsicHeight,
        public string $mime,
        public string $sourceFormat,
        public ?string $focalPoint = null,
        public bool $isAnimated = false,
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
