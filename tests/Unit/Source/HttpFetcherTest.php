<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Source;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Ynamite\Media\Exception\ImageNotFoundException;
use Ynamite\Media\Source\HttpFetcher;

/**
 * Unit-tests {@see HttpFetcher} against `Symfony\Component\HttpClient\MockHttpClient`
 * — the fixture-injection point we built the wrapper around. No live network IO.
 */
final class HttpFetcherTest extends TestCase
{
    private string $tmpBase;
    private string $destPath;

    protected function setUp(): void
    {
        $this->tmpBase = sys_get_temp_dir() . '/massif_fetcher_' . uniqid('', true);
        @mkdir($this->tmpBase, 0777, true);
        $this->destPath = $this->tmpBase . '/_origin.bin';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpBase)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmpBase, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($items as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->tmpBase);
        }
    }

    public function test200WritesBodyAndReturnsHeaders(): void
    {
        $body = str_repeat("\xff\xd8\xff", 100); // junk that smells like JPEG
        $client = new MockHttpClient(new MockResponse($body, [
            'response_headers' => [
                'ETag: "abc123"',
                'Last-Modified: Wed, 21 Oct 2026 07:28:00 GMT',
                'Content-Type: image/jpeg',
            ],
            'http_code' => 200,
        ]));

        $fetcher = new HttpFetcher($client);
        $result = $fetcher->fetch(
            url: 'https://example.com/hero.jpg',
            resolvedIp: '1.1.1.1',
            destPath: $this->destPath,
        );

        self::assertFalse($result->notModified);
        self::assertSame('"abc123"', $result->etag);
        self::assertNotNull($result->lastModified);
        self::assertFileExists($this->destPath);
        self::assertSame($body, (string) file_get_contents($this->destPath));
    }

    public function test304ReturnsNotModifiedWithoutWriting(): void
    {
        $client = new MockHttpClient(new MockResponse('', [
            'http_code' => 304,
            'response_headers' => [],
        ]));

        $fetcher = new HttpFetcher($client);
        $result = $fetcher->fetch(
            url: 'https://example.com/hero.jpg',
            resolvedIp: '1.1.1.1',
            destPath: $this->destPath,
            etag: '"abc123"',
            lastModified: 1_700_000_000,
        );

        self::assertTrue($result->notModified);
        self::assertSame('"abc123"', $result->etag);
        self::assertSame(1_700_000_000, $result->lastModified);
        self::assertFileDoesNotExist($this->destPath, 'Body must NOT be written on 304.');
    }

    public function test5xxThrows(): void
    {
        $client = new MockHttpClient(new MockResponse('Server Error', [
            'http_code' => 502,
        ]));

        $fetcher = new HttpFetcher($client);

        $this->expectException(ImageNotFoundException::class);
        $fetcher->fetch(
            url: 'https://example.com/hero.jpg',
            resolvedIp: '1.1.1.1',
            destPath: $this->destPath,
        );
    }

    public function test404Throws(): void
    {
        $client = new MockHttpClient(new MockResponse('not found', [
            'http_code' => 404,
        ]));

        $fetcher = new HttpFetcher($client);

        $this->expectException(ImageNotFoundException::class);
        $fetcher->fetch(
            url: 'https://example.com/hero.jpg',
            resolvedIp: '1.1.1.1',
            destPath: $this->destPath,
        );
    }

    public function testTransportExceptionWrapsCleanly(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('connection refused');
        });

        $fetcher = new HttpFetcher($client);

        $this->expectException(ImageNotFoundException::class);
        $this->expectExceptionMessageMatches('/transport error/');
        $fetcher->fetch(
            url: 'https://example.com/hero.jpg',
            resolvedIp: '1.1.1.1',
            destPath: $this->destPath,
        );
    }

    public function testConditionalGetHeadersPropagate(): void
    {
        // Capture-and-respond: the MockResponse callback receives the request
        // info so we can assert that If-None-Match / If-Modified-Since were
        // sent. This locks the conditional-GET wiring to the manifest.
        $capturedHeaders = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedHeaders): MockResponse {
            $capturedHeaders = $options['headers'] ?? [];
            return new MockResponse('body', ['http_code' => 200]);
        });

        $fetcher = new HttpFetcher($client);
        $fetcher->fetch(
            url: 'https://example.com/hero.jpg',
            resolvedIp: '1.1.1.1',
            destPath: $this->destPath,
            etag: '"prev-etag"',
            lastModified: 1_700_000_000,
        );

        self::assertNotNull($capturedHeaders);
        // Symfony exposes headers as either an associative array or a list of
        // `Name: value` strings depending on transport. Accept both.
        $hasIfNoneMatch = false;
        $hasIfModifiedSince = false;
        foreach ($capturedHeaders as $key => $value) {
            $line = is_int($key) ? (string) $value : $key . ': ' . $value;
            if (stripos($line, 'if-none-match') !== false && str_contains($line, '"prev-etag"')) {
                $hasIfNoneMatch = true;
            }
            if (stripos($line, 'if-modified-since') !== false) {
                $hasIfModifiedSince = true;
            }
        }
        self::assertTrue($hasIfNoneMatch, 'If-None-Match header should be sent');
        self::assertTrue($hasIfModifiedSince, 'If-Modified-Since header should be sent');
    }
}
