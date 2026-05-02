<?php

declare(strict_types=1);

namespace Ynamite\Media\Glide;

use Ynamite\Media\Config;

final class Signature
{
    /**
     * Sign a cache path. Optional $extraPayload is concatenated with `|` before
     * HMAC computation — used to cover the &f filter blob alongside the path.
     * The `|` delimiter cannot appear in a base64url-encoded JSON payload, so
     * the concatenation is unambiguous.
     */
    public static function sign(string $path, ?string $extraPayload = null, ?string $key = null): string
    {
        $key ??= Config::signKey();
        $payload = $extraPayload !== null && $extraPayload !== ''
            ? $path . '|' . $extraPayload
            : $path;
        return hash_hmac('sha256', $payload, $key);
    }

    public static function verify(string $path, string $signature, ?string $extraPayload = null, ?string $key = null): bool
    {
        if ($signature === '') {
            return false;
        }
        $key ??= Config::signKey();
        $payload = $extraPayload !== null && $extraPayload !== ''
            ? $path . '|' . $extraPayload
            : $path;
        return hash_equals(hash_hmac('sha256', $payload, $key), $signature);
    }
}
