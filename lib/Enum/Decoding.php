<?php

declare(strict_types=1);

namespace Ynamite\Media\Enum;

enum Decoding: string
{
    case AUTO = 'auto';
    case SYNC = 'sync';
    case ASYNC = 'async';
}
