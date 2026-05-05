<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Source;

use PHPUnit\Framework\TestCase;
use Ynamite\Media\Exception\ImageNotFoundException;
use Ynamite\Media\Source\SsrfGuard;

/**
 * Locks the URL-shape and IP block-list rules. The DNS resolution path uses
 * `gethostbynamel` (built-in) — exercised via literal-IP URLs so the test
 * doesn't need real DNS. Hostname resolution is covered by the integration
 * suite when a network is available.
 */
final class SsrfGuardTest extends TestCase
{
    public function testRejectsMalformedUrl(): void
    {
        $this->expectException(ImageNotFoundException::class);
        SsrfGuard::validate('not-a-url');
    }

    public function testRejectsFtpScheme(): void
    {
        $this->expectException(ImageNotFoundException::class);
        SsrfGuard::validate('ftp://example.com/foo.jpg');
    }

    public function testRejectsJavascriptScheme(): void
    {
        $this->expectException(ImageNotFoundException::class);
        SsrfGuard::validate('javascript://example.com/');
    }

    public function testRejectsLoopbackIpHost(): void
    {
        $this->expectException(ImageNotFoundException::class);
        $this->expectExceptionMessageMatches('/blocked IP/');
        SsrfGuard::validate('http://127.0.0.1/foo.jpg');
    }

    public function testRejectsPrivate10Range(): void
    {
        $this->expectException(ImageNotFoundException::class);
        SsrfGuard::validate('http://10.0.0.1/foo.jpg');
    }

    public function testRejectsPrivate172Range(): void
    {
        $this->expectException(ImageNotFoundException::class);
        SsrfGuard::validate('http://172.16.5.10/foo.jpg');
    }

    public function testRejectsPrivate192Range(): void
    {
        $this->expectException(ImageNotFoundException::class);
        SsrfGuard::validate('http://192.168.1.1/foo.jpg');
    }

    public function testRejectsLinkLocal169Range(): void
    {
        $this->expectException(ImageNotFoundException::class);
        SsrfGuard::validate('http://169.254.169.254/foo.jpg');
    }

    public function testRejectsCgnat100Range(): void
    {
        $this->expectException(ImageNotFoundException::class);
        SsrfGuard::validate('http://100.64.0.1/foo.jpg');
    }

    public function testRejectsBroadcastRange(): void
    {
        $this->expectException(ImageNotFoundException::class);
        SsrfGuard::validate('http://255.255.255.255/foo.jpg');
    }

    public function testRejectsMulticastRange(): void
    {
        $this->expectException(ImageNotFoundException::class);
        SsrfGuard::validate('http://224.0.0.1/foo.jpg');
    }

    public function testAcceptsPublicLikeIpLiteral(): void
    {
        // A literal IP outside any blocked range should pass. Cloudflare's
        // public DNS — not making a network call here, just resolving as-is.
        // gethostbynamel returns the IP back when given an IP literal.
        [$host, $ip] = SsrfGuard::validate('http://1.1.1.1/foo.jpg');
        self::assertSame('1.1.1.1', $host);
        self::assertSame('1.1.1.1', $ip);
    }

    public function testAllowlistAcceptsMatchingHost(): void
    {
        [$host] = SsrfGuard::validate(
            'http://1.1.1.1/foo.jpg',
            ['^1\\.1\\.1\\.1$', '^example\\.com$'],
        );
        self::assertSame('1.1.1.1', $host);
    }

    public function testAllowlistRejectsNonMatchingHost(): void
    {
        $this->expectException(ImageNotFoundException::class);
        $this->expectExceptionMessageMatches('/not in allowlist/');
        SsrfGuard::validate('http://1.1.1.1/foo.jpg', ['^example\\.com$']);
    }

    public function testEmptyAllowlistAcceptsAnyValidIp(): void
    {
        [$host] = SsrfGuard::validate('http://1.1.1.1/foo.jpg', []);
        self::assertSame('1.1.1.1', $host);
    }
}
