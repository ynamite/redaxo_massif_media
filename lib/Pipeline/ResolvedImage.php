<?php

declare(strict_types=1);

namespace Ynamite\Media\Pipeline;

use Ynamite\Media\Config;
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
        public int $frameCount = 1,
    ) {
    }

    public function withFocal(string $focal): self
    {
        return new self(
            source: $this->source,
            intrinsicWidth: $this->intrinsicWidth,
            intrinsicHeight: $this->intrinsicHeight,
            mime: $this->mime,
            sourceFormat: $this->sourceFormat,
            focalPoint: $focal,
            isAnimated: $this->isAnimated,
            frameCount: $this->frameCount,
        );
    }

    public function isPassthrough(): bool
    {
        return in_array($this->sourceFormat, ['svg', 'gif'], true)
            || ($this->sourceFormat === 'webp' && $this->isAnimated);
    }

    public function isAnimatedEncodable(): bool
    {
        return $this->isAnimated
            && in_array($this->sourceFormat, ['gif', 'webp'], true)
            && !$this->source->isExternal()
            && $this->intrinsicWidth > 0
            && $this->intrinsicHeight > 0
            && $this->frameCount > 0
            && $this->frameCount * $this->intrinsicWidth * $this->intrinsicHeight <= Config::animatedMaxMegapixels() * 1_000_000;
    }

    public function aspectRatio(): float
    {
        return $this->intrinsicHeight > 0
            ? $this->intrinsicWidth / $this->intrinsicHeight
            : 0.0;
    }
}
