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
        $path = 'avif-1080-50/hero.jpg.avif';

        $sig = Signature::sign($path, $key);
        self::assertNotEmpty($sig);
        self::assertTrue(Signature::verify($path, $sig, $key));
    }

    public function testVerifyRejectsTamperedPath(): void
    {
        $key = 'test-key-42';
        $sig = Signature::sign('avif-1080-50/hero.jpg.avif', $key);

        self::assertFalse(Signature::verify('avif-1080-99/hero.jpg.avif', $sig, $key));
    }

    public function testVerifyRejectsWrongKey(): void
    {
        $sig = Signature::sign('avif-1080-50/hero.jpg.avif', 'key-a');

        self::assertFalse(Signature::verify('avif-1080-50/hero.jpg.avif', $sig, 'key-b'));
    }
}
