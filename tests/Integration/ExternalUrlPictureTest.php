<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Integration;

use PHPUnit\Framework\TestCase;
use rex_config;
use rex_path;
use Ynamite\Media\Config;
use Ynamite\Media\Image;
use Ynamite\Media\Pipeline\CacheInvalidator;
use Ynamite\Media\Source\ExternalManifest;
use Ynamite\Media\Source\ExternalSource;

/**
 * End-to-end external-URL render. To avoid network IO in CI, we pre-stage a
 * valid manifest + origin under `cache/_external/<hash>/` so the factory's
 * "fresh manifest" branch hits and skips the fetch entirely. This exercises:
 *
 *   - URL → ExternalSource resolution via existing manifest.
 *   - UrlBuilder emission with `_external/<hash>` cache-bucket path.
 *   - Signature validity over the new path shape (unchanged HMAC contract).
 *   - CacheInvalidator::invalidateUrl() purges the bucket.
 *
 * The fetch / SSRF / conditional-GET layers are covered by their unit tests
 * with `MockHttpClient` — testing them again here would add network flakiness
 * without surfacing new bugs.
 */
final class ExternalUrlPictureTest extends TestCase
{
    private string $tmpBase;
    private string $url;
    private string $hash;

    protected function setUp(): void
    {
        $this->tmpBase = sys_get_temp_dir() . '/massif_ext_int_' . uniqid('', true);
        rex_path::_setBase($this->tmpBase);
        rex_config::set(Config::ADDON, Config::KEY_SIGN_KEY, 'integration-test-key');
        rex_config::set(Config::ADDON, Config::KEY_FORMATS, 'webp,jpg');
        rex_config::set(Config::ADDON, Config::KEY_LQIP_ENABLED, 0);
        rex_config::set(Config::ADDON, Config::KEY_COLOR_ENABLED, 0);
        rex_config::set(Config::ADDON, Config::KEY_EXTERNAL_TTL_SECONDS, 86_400);

        // Pre-stage a fresh manifest + origin so the factory's "fresh" branch
        // hits and the HttpFetcher is never invoked.
        $this->url = 'http://1.1.1.1/test.jpg';
        $this->hash = ExternalSource::hashFor($this->url);
        $fixturesDir = __DIR__ . '/../_fixtures';
        $originPath = ExternalManifest::originPath($this->hash);
        @mkdir(dirname($originPath), 0777, true);
        copy($fixturesDir . '/landscape-800x600.jpg', $originPath);
        ExternalManifest::write($this->hash, [
            'url' => $this->url,
            'etag' => '"e1"',
            'lastModified' => 1_700_000_000,
            'fetchedAt' => time() - 60,
            'ttl' => 86_400,
        ]);
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

    public function testExternalUrlRendersPictureWithExternalCachePath(): void
    {
        $html = Image::picture($this->url, alt: 'External');

        self::assertStringStartsWith('<picture>', $html);
        self::assertStringEndsWith('</picture>', $html);
        // Variant URLs route through `_external/<hash>` instead of a mediapool path.
        self::assertStringContainsString('_external/' . $this->hash . '/', $html);
        // No upstream URL leaks into emitted markup — the URL is hashed away.
        self::assertStringNotContainsString('http://1.1.1.1', $html);
    }

    public function testExternalUrlOmitsCdnEvenWhenCdnEnabled(): void
    {
        // CDN mode should NOT proxy external URLs through our CDN template —
        // the upstream is already a CDN, double-routing makes no sense.
        rex_config::set(Config::ADDON, Config::KEY_CDN_ENABLED, 1);
        rex_config::set(Config::ADDON, Config::KEY_CDN_BASE, 'https://cdn.example.com');
        rex_config::set(Config::ADDON, Config::KEY_CDN_URL_TEMPLATE, '{src}?w={w}');

        $html = Image::picture($this->url, alt: 'External');

        // External URL still routes through our self-served `_external/...` path.
        self::assertStringContainsString('_external/' . $this->hash . '/', $html);
        self::assertStringNotContainsString('cdn.example.com', $html);
    }

    public function testImageUrlForExternalReturnsSignedSelfServedUrl(): void
    {
        $url = Image::url($this->url, width: 800, format: 'webp');

        // Single signed URL into our cache, not the upstream.
        self::assertStringContainsString('_external/' . $this->hash . '/webp-', $url);
        self::assertStringContainsString('?s=', $url);
    }

    public function testCacheInvalidateUrlPurgesBucketAndAllSidecars(): void
    {
        // Render once so any meta sidecars get written, then invalidate.
        Image::picture($this->url, alt: 'External');

        $bucketDir = ExternalManifest::bucketDir($this->hash);
        self::assertDirectoryExists($bucketDir);

        CacheInvalidator::invalidateUrl($this->url);

        self::assertDirectoryDoesNotExist($bucketDir, 'External bucket dir must be wiped');
    }

    public function testCacheInvalidateUrlIgnoresNonUrls(): void
    {
        // Filename input must NOT match the URL branch — it's a no-op.
        $bucketDir = ExternalManifest::bucketDir($this->hash);

        CacheInvalidator::invalidateUrl('mediapool-filename.jpg');

        self::assertDirectoryExists($bucketDir, 'Mediapool-shaped input is a no-op for invalidateUrl');
    }
}
