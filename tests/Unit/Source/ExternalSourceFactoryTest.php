<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Source;

use PHPUnit\Framework\TestCase;
use rex_config;
use rex_path;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Ynamite\Media\Config;
use Ynamite\Media\Exception\ImageNotFoundException;
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

    public function testFetchFailureWritesSentinelAndSuppressesRetry(): void
    {
        // First-ever fetch fails (no body on disk) → exception AND a failure
        // sentinel in the manifest. A second resolve within the sentinel TTL
        // must fail fast WITHOUT touching the network — before the sentinel
        // existed, every page render re-paid the full transport timeout.
        $url = 'http://1.1.1.1/dead.jpg';
        $hash = ExternalSource::hashFor($url);

        $factory = new ExternalSourceFactory(new HttpFetcher(
            new MockHttpClient(new MockResponse('', ['http_code' => 500])),
        ));
        try {
            $factory->resolve($url);
            self::fail('expected ImageNotFoundException on HTTP 500');
        } catch (ImageNotFoundException) {
        }

        $manifest = ExternalManifest::read($hash);
        self::assertNotNull($manifest, 'failure must persist a manifest');
        self::assertNotNull($manifest['failedAt'], 'failure sentinel must be set');

        $noFetch = new ExternalSourceFactory(new HttpFetcher(new MockHttpClient(static function (): MockResponse {
            throw new \LogicException('fetcher must not be called within the failure-sentinel TTL');
        })));
        $this->expectException(ImageNotFoundException::class);
        $this->expectExceptionMessageMatches('/retry suppressed/');
        $noFetch->resolve($url);
    }

    public function testFetchFailureWithStaleBodyServesStale(): void
    {
        // Expired manifest, body still on disk, upstream now erroring →
        // stale-on-error: the old body is served instead of throwing, and the
        // sentinel suppresses network retries for subsequent resolves.
        $url = 'http://1.1.1.1/hero.jpg';
        $hash = ExternalSource::hashFor($url);
        $originPath = ExternalManifest::originPath($hash);
        @mkdir(dirname($originPath), 0777, true);
        file_put_contents($originPath, 'stale-bytes');
        ExternalManifest::write($hash, [
            'url' => $url,
            'etag' => '"e1"',
            'lastModified' => null,
            'fetchedAt' => time() - 100_000,
            'ttl' => 86_400,
        ]);

        $factory = new ExternalSourceFactory(new HttpFetcher(
            new MockHttpClient(new MockResponse('', ['http_code' => 503])),
        ));
        $source = $factory->resolve($url);

        self::assertSame('stale-bytes', (string) file_get_contents($source->absolutePath()));
        self::assertNotNull(ExternalManifest::read($hash)['failedAt'] ?? null, 'sentinel set alongside stale serve');

        $noFetch = new ExternalSourceFactory(new HttpFetcher(new MockHttpClient(static function (): MockResponse {
            throw new \LogicException('fetcher must not be called within the failure-sentinel TTL');
        })));
        self::assertSame('stale-bytes', (string) file_get_contents($noFetch->resolve($url)->absolutePath()));
    }

    public function testSuccessfulFetchClearsFailureSentinel(): void
    {
        // Sentinel already expired (failedAt far in the past) → fetch runs
        // again; success must clear failedAt so the bucket is healthy again.
        $url = 'http://1.1.1.1/recovered.jpg';
        $hash = ExternalSource::hashFor($url);
        ExternalManifest::write($hash, [
            'url' => $url,
            'etag' => null,
            'lastModified' => null,
            'fetchedAt' => 0,
            'ttl' => 86_400,
            'failedAt' => time() - 3_600,
        ]);

        $factory = new ExternalSourceFactory(new HttpFetcher(
            new MockHttpClient(new MockResponse('recovered-bytes', ['http_code' => 200])),
        ));
        $factory->resolve($url);

        $manifest = ExternalManifest::read($hash);
        self::assertNotNull($manifest);
        self::assertNull($manifest['failedAt'], 'success must clear the failure sentinel');
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
