<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Integration;

use PHPUnit\Framework\TestCase;
use Ynamite\Media\Glide\Endpoint;
use Ynamite\Media\Glide\Server;
use Ynamite\Media\Glide\Signature;

/**
 * Drives Endpoint::handle() end to end — signature check, disk fastpath,
 * miss-encode, 304 revalidation, 0-byte refusal.
 *
 * Coverage boundary: `header()` values are unobservable under the CLI SAPI
 * (headers_list() is empty there), so header strings stay unasserted. What
 * IS asserted is everything that can regress harmfully: response bodies,
 * `http_response_code()`, and files created on disk. The hit-fastpath test
 * proves the fastpath structurally — it serves a cache file whose SOURCE
 * does not exist, which the Glide pipeline could never do.
 */
final class EndpointServeTest extends TestCase
{
    private const KEY = 'endpoint-serve-test-key';

    private string $tmpBase;
    private string $mediaDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->tmpBase = sys_get_temp_dir() . '/massif_endpoint_' . uniqid('', true);
        $this->mediaDir = $this->tmpBase . '/media';
        $this->cacheDir = $this->tmpBase . '/assets/addons/massif_media/cache';
        @mkdir($this->mediaDir, 0777, true);
        @mkdir($this->cacheDir, 0777, true);

        \rex_path::_setBase($this->tmpBase);
        \rex_config::set('massif_media', 'sign_key', self::KEY);

        // CLI SAPI: http_response_code() returns false until something sets
        // it. Normalize the baseline so "still 200 after handle()" reliably
        // means "no error response was issued".
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        \rex_config::_reset();
        \rex_dir::delete($this->tmpBase, true);
        unset($_GET['p'], $_GET['s'], $_GET['f'], $_SERVER['HTTP_IF_NONE_MATCH']);
        http_response_code(200);
        Server::clearActiveFilters();
    }

    /** Run Endpoint::handle() with a signed request, return the emitted body. */
    private function handle(string $cachePath): string
    {
        $_GET['p'] = $cachePath;
        $_GET['s'] = Signature::sign($cachePath, key: self::KEY);
        ob_start();
        try {
            Endpoint::handle();
        } finally {
            $body = (string) ob_get_clean();
        }
        return $body;
    }

    public function testHitServesFromDiskWithoutTouchingSource(): void
    {
        // Cache file exists, source does NOT — only the disk fastpath can
        // serve this; any path through Glide would fail on the missing source.
        $cachePath = 'ghost.jpg/webp-32-80.webp';
        @mkdir($this->cacheDir . '/ghost.jpg', 0777, true);
        file_put_contents($this->cacheDir . '/' . $cachePath, 'staged-webp-bytes');

        $body = $this->handle($cachePath);

        self::assertSame('staged-webp-bytes', $body);
        self::assertSame(200, http_response_code());
    }

    public function testMissEncodesVariantAtUrlDerivedPath(): void
    {
        copy(__DIR__ . '/../_fixtures/landscape-800x600.jpg', $this->mediaDir . '/hero.jpg');
        $cachePath = 'hero.jpg/jpg-200-80.jpg';

        $body = $this->handle($cachePath);

        // The load-bearing invariant: the encode landed exactly where the URL
        // says, so the next request (PHP fastpath or web-server static serve)
        // finds it. Drift here means silent re-encode on every request.
        self::assertFileExists($this->cacheDir . '/' . $cachePath);
        self::assertSame(200, http_response_code());
        self::assertSame((string) file_get_contents($this->cacheDir . '/' . $cachePath), $body);
        [$w] = getimagesize($this->cacheDir . '/' . $cachePath);
        self::assertSame(200, $w);
        self::assertFileExists(
            $this->cacheDir . '/' . $cachePath . '.lock',
            'miss path must have taken the per-variant lock',
        );
    }

    public function testIfNoneMatchReturns304WithEmptyBody(): void
    {
        $cachePath = 'ghost.jpg/webp-32-80.webp';
        $abs = $this->cacheDir . '/' . $cachePath;
        @mkdir(dirname($abs), 0777, true);
        file_put_contents($abs, 'staged-webp-bytes');

        // ETag contract: dechex(mtime)-dechex(size), quoted.
        $_SERVER['HTTP_IF_NONE_MATCH'] = '"' . dechex((int) filemtime($abs)) . '-' . dechex((int) filesize($abs)) . '"';
        $body = $this->handle($cachePath);

        self::assertSame('', $body);
        self::assertSame(304, http_response_code());
    }

    public function testZeroByteCacheFileIsNeverServedAsEmpty200(): void
    {
        // The broken-AVIF shape: 0-byte cache file, no source to regenerate
        // from. Must 404, not serve an empty 200.
        $cachePath = 'ghost.jpg/avif-640-50.avif';
        @mkdir($this->cacheDir . '/ghost.jpg', 0777, true);
        file_put_contents($this->cacheDir . '/' . $cachePath, '');

        $body = $this->handle($cachePath);

        self::assertSame(404, http_response_code());
        self::assertNotSame('', $body, '404 responses carry a plain-text body');
    }

    public function testInvalidSignatureIs403(): void
    {
        $_GET['p'] = 'hero.jpg/jpg-200-80.jpg';
        $_GET['s'] = 'not-a-valid-signature';
        ob_start();
        try {
            Endpoint::handle();
        } finally {
            ob_end_clean();
        }

        self::assertSame(403, http_response_code());
    }
}
