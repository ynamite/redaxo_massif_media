<?php

declare(strict_types=1);

namespace Ynamite\Media\Enum;

enum FetchPriority: string
{
    case AUTO = 'auto';
    case HIGH = 'high';
    case LOW = 'low';
}
