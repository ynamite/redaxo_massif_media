<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Pipeline;

use PHPUnit\Framework\TestCase;
use rex_logger;
use rex_path;
use Ynamite\Media\Pipeline\WatermarkResolver;
use Ynamite\Media\Source\ExternalSource;

/**
 * WatermarkResolver translates user-supplied `mark` values to paths the Glide
 * watermarks filesystem can read (rooted at rex_path::base()).
 */
final class WatermarkResolverTest extends TestCase
{
    protected function setUp(): void
    {
        rex_path::_setBase(sys_get_temp_dir() . '/massif_wmark_resolver_' . uniqid('', true));
        rex_path::_setFrontendRel('');
        rex_logger::_reset();
    }

    protected function tearDown(): void
    {
        rex_path::_setFrontendRel('');
    }

    public function testMediapoolFilenameGetsMediaPrefix(): void
    {
        $resolver = new WatermarkResolver();
        self::assertSame('media/logo.png', $resolver->resolve('logo.png'));
    }

    public function testMediapoolSubpathPreservesStructure(): void
    {
        $resolver = new WatermarkResolver();
        self::assertSame('media/brand/logo-2024.png', $resolver->resolve('brand/logo-2024.png'));
    }

    public function testLeadingSlashPathIsTreatedAsRootRelative(): void
    {
        // A leading-slash value that's NOT one of our cache URLs: strip
        // leading slash + query, treat the rest verbatim as a path under
        // rex_path::frontend(). Different addon's assets dir, third-party
        // path, etc.
        $resolver = new WatermarkResolver();
        self::assertSame(
            'assets/addons/other-addon/img.webp',
            $resolver->resolve('/assets/addons/other-addon/img.webp'),
        );
    }

    public function testOwnGlideCacheUrlRoutesToSourceMediapoolFile(): void
    {
        // Nested `REX_PIC[src='viterex.png' as='url']` produces a URL like
        // `/assets/addons/massif_media/cache/viterex.png/webp-256-80.webp?s=…`.
        // The cached variant only exists on disk after the browser has
        // fetched it once, so using the URL verbatim as a watermark server-
        // side leads to a missing-file no-op. Resolver detects our own
        // cache-URL pattern and routes to the SOURCE file (`media/viterex.png`)
        // — Glide's Watermark manipulator then resizes the source via the
        // standard markw/markh path.
        $resolver = new WatermarkResolver();
        self::assertSame(
            'media/viterex.png',
            $resolver->resolve('/assets/addons/massif_media/cache/viterex.png/webp-256-80.webp?s=abc123&v=1700000000'),
        );
    }

    public function testOwnGlideCacheUrlPreservesMediapoolSubdir(): void
    {
        // `<src>` portion of the cache path can include subdirectories
        // (`subdir/viterex.png`). Routing must preserve them.
        $resolver = new WatermarkResolver();
        self::assertSame(
            'media/branding/logos/viterex.png',
            $resolver->resolve('/assets/addons/massif_media/cache/branding/logos/viterex.png/jpg-256-80.jpg'),
        );
    }

    public function testHttpsUrlResolvesToExternalCachePath(): void
    {
        // Stub an external source whose absolutePath is reachable from
        // rex_path::base() — that's what WatermarkResolver requires for the
        // substring relativisation. (No real HTTP fetch — closure returns
        // the canned source.)
        $hash = 'abc123def456';
        $absoluteOrigin = rex_path::base('assets/addons/massif_media/cache/_external/' . $hash . '/_origin.bin');
        $source = self::makeSource('https://example.com/logo.png', $hash, $absoluteOrigin);

        $resolver = new WatermarkResolver(static fn (string $url): ExternalSource => $source);
        $resolved = $resolver->resolve('https://example.com/logo.png');

        self::assertSame(
            'assets/addons/massif_media/cache/_external/' . $hash . '/_origin.bin',
            $resolved,
        );
    }

    public function testHttpsUrlFetchFailureReturnsNull(): void
    {
        // SSRF block, network error, 4xx — anything thrown by the resolver
        // becomes "no watermark, log and move on" rather than 500-ing.
        $resolver = new WatermarkResolver(static function (string $url): ExternalSource {
            throw new \RuntimeException('SSRF: private IP refused');
        });
        $result = $resolver->resolve('https://10.0.0.1/internal-logo.png');

        self::assertNull($result);
        self::assertCount(1, rex_logger::$logged);
    }

    public function testExternalOriginOutsideBaseReturnsNull(): void
    {
        // Misconfigured assets dir → origin lands outside rex_path::base().
        // We can't compute a relative path; bail out rather than handing
        // Glide an absolute path that the watermarks FS would reject.
        $source = self::makeSource('https://example.com/logo.png', 'xyz', '/var/somewhere/_origin.bin');
        $resolver = new WatermarkResolver(static fn (string $url): ExternalSource => $source);

        self::assertNull($resolver->resolve('https://example.com/logo.png'));
    }

    public function testViterexLayoutMediapoolMarkResolvesCorrectly(): void
    {
        // Regression: Viterex (and other installers) offset the public dir
        // to `<base>/public/`. The watermarks FS is anchored at
        // rex_path::frontend(), and the resolver returns `media/<filename>`
        // — so Glide reads `<frontend>/media/<filename>` regardless of
        // whether <frontend> equals <base> (default) or <base>/public/
        // (Viterex). This test only locks the resolver output; the FS-root
        // assertion lives in ServerTest::testMediapoolServerConfiguresWatermarksFilesystem.
        rex_path::_setFrontendRel('public');

        $resolver = new WatermarkResolver();
        // Output is unchanged from the default-layout case — the path
        // translation is identical; only the FS-root anchor moves.
        self::assertSame('media/logo.png', $resolver->resolve('logo.png'));
    }

    public function testViterexLayoutExternalMarkRelativisesAgainstFrontend(): void
    {
        // Viterex case for external watermark fetches. Origin lands at
        // `<base>/public/assets/...`; resolver must produce
        // `assets/addons/...` (relative to frontend(), not base()) so Glide
        // reads `<frontend>/assets/...` correctly.
        rex_path::_setFrontendRel('public');

        $hash = 'viterex-hash';
        $absoluteOrigin = rex_path::frontend('assets/addons/massif_media/cache/_external/' . $hash . '/_origin.bin');
        $source = self::makeSource('https://example.com/logo.png', $hash, $absoluteOrigin);

        $resolver = new WatermarkResolver(static fn (string $url): ExternalSource => $source);
        self::assertSame(
            'assets/addons/massif_media/cache/_external/' . $hash . '/_origin.bin',
            $resolver->resolve('https://example.com/logo.png'),
        );
    }

    private static function makeSource(string $url, string $hash, string $absolutePath): ExternalSource
    {
        return new ExternalSource(
            url: $url,
            hash: $hash,
            absolutePath: $absolutePath,
            fetchedAt: 1700000000,
            etag: null,
            remoteLastModified: null,
            ttlSeconds: 86400,
        );
    }
}
