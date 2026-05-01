<?php

declare(strict_types=1);

namespace Ynamite\Media\Enum;

enum Fit: string
{
    case COVER = 'cover';
    case CONTAIN = 'contain';
    case STRETCH = 'stretch';
    case NONE = 'none';
}
