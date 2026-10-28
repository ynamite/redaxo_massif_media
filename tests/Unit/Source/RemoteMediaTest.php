<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Source;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ynamite\Media\Source\RemoteMedia;

final class RemoteMediaTest extends TestCase
{
    /** @return iterable<string, array{string, ?string, ?string}> */
    public static function cases(): iterable
    {
        yield 'hosts differ → fallback' => ['http://new.example.hosting/', 'example.test', 'http://new.example.hosting/'];
        yield 'missing trailing slash' => ['https://example.ch', 'example.test', 'https://example.ch/'];
        yield 'subdirectory install' => ['https://example.ch/site/', 'example.test', 'https://example.ch/site/'];
        yield 'same host (production)' => ['https://example.ch/', 'example.ch', null];
        yield 'same host, request port' => ['https://example.ch/', 'example.ch:8080', null];
        yield 'same host, www differs' => ['https://www.example.ch/', 'example.ch', null];
        yield 'same host, case differs' => ['https://Example.ch/', 'example.CH', null];
        yield 'no request host (CLI)' => ['https://example.ch/', null, null];
        yield 'empty request host' => ['https://example.ch/', '', null];
        yield '.test server' => ['http://example.test/', 'other.test', null];
        yield 'localhost server' => ['http://localhost/', 'example.test', null];
        yield 'REDAXO install default' => ['https://www.redaxo.org/', 'example.test', null];
        yield 'empty server' => ['', 'example.test', null];
    }

    #[DataProvider('cases')]
    public function testBase(string $server, ?string $requestHost, ?string $expected): void
    {
        self::assertSame($expected, RemoteMedia::base($server, $requestHost));
    }
}
