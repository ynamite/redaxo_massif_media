<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Integration;

use PHPUnit\Framework\TestCase;
use rex;
use rex_config;
use rex_logger;
use rex_media;
use rex_path;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Ynamite\Media\Config;
use Ynamite\Media\Exception\ImageNotFoundException;
use Ynamite\Media\Image;
use Ynamite\Media\Pipeline\ImageResolver;
use Ynamite\Media\Pipeline\MetadataReader;
use Ynamite\Media\Source\ExternalManifest;
use Ynamite\Media\Source\ExternalSource;
use Ynamite\Media\Source\ExternalSourceFactory;
use Ynamite\Media\Source\HttpFetcher;

/**
 * Local-dev fallback: a mediapool file missing on disk is fetched from the
 * live site's `/media/` (REDAXO `server:`) when that host differs from the
 * request host. `1.1.1.1` is an IP literal that passes the SSRF guard without
 * DNS; the fetch itself is served by MockHttpClient.
 */
final class RemoteMediaFallbackTest extends TestCase
{
    private string $tmpBase;
    /** @var list<string> */
    private array $requested = [];

    protected function setUp(): void
    {
        $this->tmpBase = sys_get_temp_dir() . '/massif_remote_' . uniqid('', true);
        rex_path::_setBase($this->tmpBase);
        @mkdir(rex_path::media(), 0777, true);
        rex_config::set(Config::ADDON, Config::KEY_SIGN_KEY, 'integration-test-key');
        rex_config::set(Config::ADDON, Config::KEY_EXTERNAL_TTL_SECONDS, 86_400);
        rex_config::set(Config::ADDON, Config::KEY_EXTERNAL_TIMEOUT_SECONDS, 15);
        rex_config::set(Config::ADDON, Config::KEY_EXTERNAL_MAX_BYTES, 26_214_400);
        rex::_setServer('http://1.1.1.1/');
        $_SERVER['HTTP_HOST'] = 'example.test';
    }

    protected function tearDown(): void
    {
        rex_config::_reset();
        rex_logger::_reset();
        rex::_setServer('https://www.redaxo.org/');
        rex::_setDebug(false);
        unset($_SERVER['HTTP_HOST']);
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

    private function resolver(int $status = 200): ImageResolver
    {
        $client = new MockHttpClient(function (string $method, string $url) use ($status): MockResponse {
            $this->requested[] = $url;
            return new MockResponse(
                $status === 200 ? (string) file_get_contents(__DIR__ . '/../_fixtures/landscape-800x600.jpg') : '',
                ['http_code' => $status],
            );
        });
        return new ImageResolver(new MetadataReader(), externalFactory: new ExternalSourceFactory(new HttpFetcher($client)));
    }

    public function testFallsBackToRemoteWhenHostsDiffer(): void
    {
        // A configured allowlist must not block the config-derived host.
        rex_config::set(Config::ADDON, Config::KEY_EXTERNAL_HOST_ALLOWLIST, 'cdn\.example\.com');

        $image = $this->resolver()->resolve('hero image.jpg');

        self::assertInstanceOf(ExternalSource::class, $image->source);
        self::assertSame('http://1.1.1.1/media/hero%20image.jpg', $image->source->url);
        self::assertSame(800, $image->intrinsicWidth);
        self::assertSame(['http://1.1.1.1/media/hero%20image.jpg'], $this->requested);
    }

    public function testKeepsFocalPointFromRexMedia(): void
    {
        $media = new rex_media('hero.jpg', ['med_focuspoint' => '30,70']);

        $image = $this->resolver()->resolve($media);

        self::assertInstanceOf(ExternalSource::class, $image->source);
        self::assertSame('30% 70%', $image->focalPoint);
    }

    public function testNoFallbackOnSameHost(): void
    {
        $_SERVER['HTTP_HOST'] = '1.1.1.1';
        $this->assertNotFoundWithoutRequest();
    }

    public function testNoFallbackWithoutRequestHost(): void
    {
        unset($_SERVER['HTTP_HOST']);
        $this->assertNotFoundWithoutRequest();
    }

    public function testNoFallbackForTestServer(): void
    {
        rex::_setServer('http://example.test/');
        $_SERVER['HTTP_HOST'] = 'other.test';
        $this->assertNotFoundWithoutRequest();
    }

    public function testRemoteFailureThrowsNotFound(): void
    {
        try {
            $this->resolver(404)->resolve('hero.jpg');
            self::fail('expected ImageNotFoundException');
        } catch (ImageNotFoundException $e) {
            self::assertStringContainsString('hero.jpg', $e->getMessage());
            self::assertStringContainsString('remote fallback failed', $e->getMessage());
        }
    }

    public function testRemoteFailureRendersMissingSrcMarker(): void
    {
        // Pre-stage a recent fetch failure so the default factory fails fast
        // from the negative cache instead of touching the network.
        $url = 'http://1.1.1.1/media/hero.jpg';
        ExternalManifest::write(ExternalSource::hashFor($url), [
            'url' => $url,
            'etag' => null,
            'lastModified' => null,
            'fetchedAt' => 0,
            'ttl' => 86_400,
            'failedAt' => time(),
        ]);
        rex::_setDebug(true);

        self::assertSame('<!-- massif_media: src not found "hero.jpg" -->', Image::picture('hero.jpg'));
    }

    private function assertNotFoundWithoutRequest(): void
    {
        try {
            $this->resolver()->resolve('hero.jpg');
            self::fail('expected ImageNotFoundException');
        } catch (ImageNotFoundException $e) {
            self::assertStringNotContainsString('remote fallback', $e->getMessage());
        }
        self::assertSame([], $this->requested);
    }
}
