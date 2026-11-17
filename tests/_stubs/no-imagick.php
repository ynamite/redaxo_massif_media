<?php

declare(strict_types=1);

namespace Ynamite\Media\Pipeline;

// Loaded only in isolated tests so real metadata and encoder paths see a GD-only host.
function extension_loaded(string $extension): bool
{
    return $extension !== 'imagick' && \extension_loaded($extension);
}
