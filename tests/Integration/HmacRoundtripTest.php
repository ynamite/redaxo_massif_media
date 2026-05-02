<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Integration;

use PHPUnit\Framework\TestCase;
use Ynamite\Media\Glide\Server;
use Ynamite\Media\Glide\Signature;

final class HmacRoundtripTest extends TestCase
{
    private const KEY = 'integration-test-key-42';

    protected function setUp(): void
    {
        \rex_config::_reset();
        \rex_config::set('massif_media', 'sign_key', self::KEY);
    }

    public function testLegacyShapeSignsAndVerifies(): void
    {
        $path = Server::cachePath('hero.jpg', ['fm' => 'avif', 'w' => 1080, 'q' => 50]);
        $sig = Signature::sign($path, key: self::KEY);

        self::assertTrue(Signature::verify($path, $sig, key: self::KEY));
    }

    public function testCropShapeSignsAndVerifies(): void
    {
        $path = Server::cachePath('hero.jpg', [
            'fm' => 'avif', 'w' => 1080, 'q' => 50,
            'h' => 1080, 'fit' => 'cover-50-50',
        ]);
        $sig = Signature::sign($path, key: self::KEY);

        self::assertTrue(Signature::verify($path, $sig, key: self::KEY));
    }

    public function testTamperedPathFailsVerification(): void
    {
        $path = Server::cachePath('hero.jpg', ['fm' => 'avif', 'w' => 1080, 'q' => 50]);
        $sig = Signature::sign($path, key: self::KEY);
        $tampered = str_replace('1080', '4096', $path);

        self::assertFalse(Signature::verify($tampered, $sig, key: self::KEY));
    }

    public function testWrongKeyFailsVerification(): void
    {
        $path = Server::cachePath('hero.jpg', ['fm' => 'avif', 'w' => 1080, 'q' => 50]);
        $sig = Signature::sign($path, key: self::KEY);

        self::assertFalse(Signature::verify($path, $sig, key: 'different-key'));
    }

    public function testExtraPayloadRoundtripVerifies(): void
    {
        $path = Server::cachePath('hero.jpg', [
            'fm' => 'jpg', 'w' => 800, 'q' => 80,
            'filters' => ['bri' => 10],
        ]);
        $extra = 'eyJicmkiOjEwfQ';
        $sig = Signature::sign($path, $extra, self::KEY);

        self::assertTrue(Signature::verify($path, $sig, $extra, self::KEY));
    }

    public function testTamperedExtraPayloadFailsVerification(): void
    {
        $path = Server::cachePath('hero.jpg', [
            'fm' => 'jpg', 'w' => 800, 'q' => 80,
            'filters' => ['bri' => 10],
        ]);
        $extra = 'eyJicmkiOjEwfQ';
        $sig = Signature::sign($path, $extra, self::KEY);

        self::assertFalse(Signature::verify($path, $sig, 'eyJicmkiOjk5fQ', self::KEY));
    }
}
