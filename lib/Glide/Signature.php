<?php

declare(strict_types=1);

namespace Ynamite\Media\Glide;

use Ynamite\Media\Config;

final class Signature
{
    public static function sign(string $cachePath): string
    {
        return self::compute($cachePath);
    }

    public static function verify(string $cachePath, string $signature): bool
    {
        if ($signature === '' || Config::signKey() === '') {
            return false;
        }
        return hash_equals(self::compute($cachePath), $signature);
    }

    private static function compute(string $cachePath): string
    {
        return hash_hmac('sha256', $cachePath, Config::signKey());
    }
}
