<?php

declare(strict_types=1);

namespace Ynamite\Media\Glide;

use Ynamite\Media\Config;

final class Signature
{
    public static function sign(string $path, ?string $key = null): string
    {
        $key ??= Config::signKey();
        return hash_hmac('sha256', $path, $key);
    }

    public static function verify(string $path, string $signature, ?string $key = null): bool
    {
        if ($signature === '') {
            return false;
        }
        $key ??= Config::signKey();
        return hash_equals(hash_hmac('sha256', $path, $key), $signature);
    }
}
