<?php

declare(strict_types=1);

namespace Ynamite\Media\Enum;

enum Loading: string
{
    case LAZY = 'lazy';
    case EAGER = 'eager';
}
