<?php

declare(strict_types=1);

namespace Ynamite\Media\Glide;

/**
 * Single source of truth for the filter-param hash + encoded blob used in
 * cache paths and URLs. The recipe is shared by three sites that must agree
 * byte-for-byte (URL emission, signature verification, on-disk cache path
 * computation) — centralizing the canonical-JSON step here removes the drift
 * risk that would arise if any one site forgot to ksort or chose a different
 * json_encode flag set.
 *
 * @phpstan-type FilterParams array<string, scalar>
 */
final class CacheKeyBuilder
{
    /**
     * Hash filter params into the 8-char hex suffix used in `-f{hash}` cache-path
     * segments. ksort first so call order doesn't change the hash.
     *
     * @param FilterParams $params
     */
    public static function hashFilterParams(array $params): string
    {
        return substr(md5(self::canonicalJson($params)), 0, 8);
    }

    /**
     * Encode filter params into the base64url-encoded JSON blob used in the
     * `&f=` query parameter. Inverse of decodeFilterBlob.
     *
     * @param FilterParams $params
     */
    public static function encodeFilterBlob(array $params): string
    {
        return self::base64UrlEncode(self::canonicalJson($params));
    }

    public static function decodeFilterBlob(string $blob): string|false
    {
        return base64_decode(strtr($blob, '-_', '+/'), true);
    }

    /**
     * @param FilterParams $params
     */
    private static function canonicalJson(array $params): string
    {
        ksort($params);
        return json_encode($params, JSON_FORCE_OBJECT);
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
