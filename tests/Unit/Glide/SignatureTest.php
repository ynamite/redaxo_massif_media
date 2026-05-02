<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Glide;

use PHPUnit\Framework\TestCase;
use Ynamite\Media\Glide\Signature;

final class SignatureTest extends TestCase
{
    public function testSignVerifyRoundtripWithInjectedKey(): void
    {
        $key = 'test-key-42';
        $path = 'hero.jpg/avif-1080-50.avif';

        $sig = Signature::sign($path, key: $key);
        self::assertNotEmpty($sig);
        self::assertTrue(Signature::verify($path, $sig, key: $key));
    }

    public function testVerifyRejectsTamperedPath(): void
    {
        $key = 'test-key-42';
        $sig = Signature::sign('hero.jpg/avif-1080-50.avif', key: $key);

        self::assertFalse(Signature::verify('hero.jpg/avif-1080-99.avif', $sig, key: $key));
    }

    public function testVerifyRejectsWrongKey(): void
    {
        $sig = Signature::sign('hero.jpg/avif-1080-50.avif', key: 'key-a');

        self::assertFalse(Signature::verify('hero.jpg/avif-1080-50.avif', $sig, key: 'key-b'));
    }

    public function testSignWithExtraPayload(): void
    {
        $key = 'test-key';
        $path = 'hero.jpg/jpg-800-80-fa1b2c3d4.jpg';
        $extra = 'eyJicmkiOjEwfQ'; // pretend filter blob

        $sig = Signature::sign($path, $extra, $key);
        self::assertNotEmpty($sig);
        self::assertTrue(Signature::verify($path, $sig, $extra, $key));
    }

    public function testVerifyRejectsTamperedExtraPayload(): void
    {
        $key = 'test-key';
        $path = 'hero.jpg/jpg-800-80-fa1b2c3d4.jpg';
        $extra = 'eyJicmkiOjEwfQ';

        $sig = Signature::sign($path, $extra, $key);
        self::assertFalse(Signature::verify($path, $sig, 'eyJicmkiOjk5fQ', $key));
    }

    public function testVerifyRejectsNullExtraWhenSignedWithExtra(): void
    {
        $key = 'test-key';
        $path = 'hero.jpg/jpg-800-80-fa1b2c3d4.jpg';

        $sig = Signature::sign($path, 'eyJicmkiOjEwfQ', $key);
        self::assertFalse(Signature::verify($path, $sig, null, $key));
    }
}
