<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Source;

use PHPUnit\Framework\TestCase;
use rex_config;
use rex_path;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Ynamite\Media\Config;
use Ynamite\Media\Source\ExternalManifest;
use Ynamite\Media\Source\ExternalSource;
use Ynamite\Media\Source\ExternalSourceFactory;
use Ynamite\Media\Source\HttpFetcher;

/**
 * Locks the cache-fresh / TTL-expiry / 304-roundtrip orchestration. The
 * SsrfGuard step is exercised through real `gethostbynamel` against the IP
 * literal `1.1.1.1`, which resolves as itself and passes the public-IP check.
 */
final class ExternalSourceFactoryTest extends TestCase
{
    private string $tmpBase;

    protected function setUp(): void
    {
        $this->tmpBase = sys_get_temp_dir() . '/massif_extfac_' . uniqid('', true);
        rex_path::_setBase($this->tmpBase);
        rex_config::set(Config::ADDON, Config::KEY_EXTERNAL_TTL_SECONDS, 86_400);
        rex_config::set(Config::ADDON, Config::KEY_EXTERNAL_TIMEOUT_SECONDS, 15);
        rex_config::set(Config::ADDON, Config::KEY_EXTERNAL_MAX_BYTES, 26_214_400);
    }

    protected function tearDown(): void
    {
        rex_config::_reset();
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

    public function testFreshManifestSkipsFetch(): void
    {
        // Pre-stage manifest + origin within TTL → factory must NOT fetch.
        $url = 'http://1.1.1.1/hero.jpg';
        $hash = ExternalSource::hashFor($url);
        $originPath = ExternalManifest::originPath($hash);
        @mkdir(dirname($originPath), 0777, true);
        file_put_contents($originPath, 'pretend-jpeg-bytes');
        ExternalManifest::write($hash, [
            'url' => $url,
            'etag' => '"e1"',
            'lastModified' => 1_700_000_000,
            'fetchedAt' => time() - 60,
            'ttl' => 86_400,
        ]);

        $client = new MockHttpClient(static function (): MockResponse {
            throw new \LogicException('fetcher should not be called when manifest is fresh');
        });
        $factory = new ExternalSourceFactory(new HttpFetcher($client));

        $source = $factory->resolve($url);

        self::assertSame($hash, $source->hash);
        self::assertSame($url, $source->url);
        self::assertSame('"e1"', $source->etag);
    }

    public function testExpiredManifestTriggersConditionalGetWith304BumpsFetchedAt(): void
    {
        // Manifest exists but is past TTL. Conditional GET answers 304;
        // fetchedAt advances, body untouched, etag preserved.
        $url = 'http://1.1.1.1/hero.jpg';
        $hash = ExternalSource::hashFor($url);
        $originPath = ExternalManifest::originPath($hash);
        @mkdir(dirname($originPath), 0777, true);
        file_put_contents($originPath, 'old-bytes');
        ExternalManifest::write($hash, [
            'url' => $url,
            'etag' => '"e1"',
            'lastModified' => 1_700_000_000,
            'fetchedAt' => time() - 100_000,
            'ttl' => 86_400,
        ]);

        $client = new MockHttpClient(new MockResponse('', ['http_code' => 304]));
        $factory = new ExternalSourceFactory(new HttpFetcher($client));

        $before = time();
        $source = $factory->resolve($url);

        self::assertGreaterThanOrEqual($before, $source->fetchedAt, 'fetchedAt must move forward on 304 bump');
        self::assertSame('"e1"', $source->etag, 'etag preserved on 304');
        self::assertSame('old-bytes', (string) file_get_contents($originPath), 'body unchanged on 304');
    }

    public function testNoManifestTriggersFreshFetch(): void
    {
        $url = 'http://1.1.1.1/new.jpg';
        $hash = ExternalSource::hashFor($url);

        $newBody = 'fresh-jpeg-bytes';
        $client = new MockHttpClient(new MockResponse($newBody, [
            'http_code' => 200,
            'response_headers' => ['ETag: "fresh"'],
        ]));
        $factory = new ExternalSourceFactory(new HttpFetcher($client));

        $source = $factory->resolve($url);

        self::assertSame($hash, $source->hash);
        self::assertSame($newBody, (string) file_get_contents(ExternalManifest::originPath($hash)));
        self::assertSame('"fresh"', $source->etag);
        self::assertNotNull(ExternalManifest::read($hash), 'manifest must be persisted');
    }

    public function testResolveByHashReturnsNullWhenManifestMissing(): void
    {
        $factory = new ExternalSourceFactory(new HttpFetcher(new MockHttpClient([])));
        self::assertNull($factory->resolveByHash('does-not-exist'));
    }

    public function testResolveByHashHydratesFromManifest(): void
    {
        $url = 'http://1.1.1.1/hero.jpg';
        $hash = ExternalSource::hashFor($url);
        ExternalManifest::write($hash, [
            'url' => $url,
            'etag' => '"e1"',
            'lastModified' => 1_700_000_000,
            'fetchedAt' => 1_700_000_500,
            'ttl' => 86_400,
        ]);

        $factory = new ExternalSourceFactory(new HttpFetcher(new MockHttpClient([])));
        $source = $factory->resolveByHash($hash);

        self::assertNotNull($source);
        self::assertSame($url, $source->url);
        self::assertSame(1_700_000_500, $source->fetchedAt);
        self::assertStringEndsWith('/_origin.bin', $source->absolutePath);
    }

    public function testHashIsStableForSameUrl(): void
    {
        // The cache-bucket id is content-addressed. Same URL = same hash on
        // every render so subsequent calls hit the same bucket.
        self::assertSame(
            ExternalSource::hashFor('https://example.com/foo.jpg'),
            ExternalSource::hashFor('https://example.com/foo.jpg'),
        );
        self::assertNotSame(
            ExternalSource::hashFor('https://example.com/foo.jpg'),
            ExternalSource::hashFor('https://example.com/foo.png'),
        );
    }
}
