<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Glide;

use PHPUnit\Framework\TestCase;
use Ynamite\Media\Glide\CacheKeyBuilder;

final class CacheKeyBuilderTest extends TestCase
{
    public function testHashFilterParamsIs8HexChars(): void
    {
        $hash = CacheKeyBuilder::hashFilterParams(['bri' => 10]);

        self::assertSame(8, strlen($hash));
        self::assertMatchesRegularExpression('/^[a-f0-9]{8}$/', $hash);
    }

    public function testHashFilterParamsIsDeterministic(): void
    {
        self::assertSame(
            CacheKeyBuilder::hashFilterParams(['bri' => 10, 'sharp' => 5]),
            CacheKeyBuilder::hashFilterParams(['bri' => 10, 'sharp' => 5]),
        );
    }

    public function testHashFilterParamsIsKsortIndependent(): void
    {
        // Whether the caller writes ['a' => 1, 'b' => 2] or ['b' => 2, 'a' => 1],
        // the hash MUST be identical — otherwise URL emission and cache-path
        // computation would drift apart whenever filter args were assembled in
        // a non-canonical order.
        self::assertSame(
            CacheKeyBuilder::hashFilterParams(['bri' => 10, 'sharp' => 5]),
            CacheKeyBuilder::hashFilterParams(['sharp' => 5, 'bri' => 10]),
        );
    }

    public function testEncodeFilterBlobIsKsortIndependent(): void
    {
        self::assertSame(
            CacheKeyBuilder::encodeFilterBlob(['bri' => 10, 'sharp' => 5]),
            CacheKeyBuilder::encodeFilterBlob(['sharp' => 5, 'bri' => 10]),
        );
    }

    public function testEncodeBlobUsesUrlSafeAlphabet(): void
    {
        $blob = CacheKeyBuilder::encodeFilterBlob(['bri' => 10]);

        // base64url replaces '+' / '/' with '-' / '_' and strips '=' padding.
        self::assertStringNotContainsString('+', $blob);
        self::assertStringNotContainsString('/', $blob);
        self::assertStringNotContainsString('=', $blob);
    }

    public function testEncodeDecodeRoundtrip(): void
    {
        $params = ['bri' => 10, 'sharp' => 5, 'flip' => 'h'];
        $blob = CacheKeyBuilder::encodeFilterBlob($params);
        $decoded = CacheKeyBuilder::decodeFilterBlob($blob);

        self::assertIsString($decoded);
        // Keys come back ksort'd — that's the canonical form encode produces.
        self::assertEqualsCanonicalizing($params, json_decode($decoded, true));
    }

    public function testDecodeFilterBlobReturnsFalseOnInvalidInput(): void
    {
        // base64_decode strict mode rejects non-alphabet characters.
        self::assertFalse(CacheKeyBuilder::decodeFilterBlob('!!! not base64 !!!'));
    }
}
