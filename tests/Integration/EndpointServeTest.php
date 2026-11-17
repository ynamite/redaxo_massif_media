<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Integration;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Ynamite\Media\Config;
use Ynamite\Media\Glide\Endpoint;
use Ynamite\Media\Glide\CacheKeyBuilder;
use Ynamite\Media\Glide\Server;
use Ynamite\Media\Glide\Signature;
use Ynamite\Media\Pipeline\MetadataReader;
use Ynamite\Media\Source\MediapoolSourceFactory;

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

    public function testServesSuffixedWidth(): void
    {
        if (!extension_loaded('imagick') || \Imagick::queryFormats('WEBP') === []) {
            self::markTestSkipped('Animated WebP encoding needs the Imagick WebP delegate.');
        }
        copy(__DIR__ . '/../_fixtures/animated-700.webp', $this->mediaDir . '/animation.webp');
        $cachePath = 'animation.webp/animated-640.webp';

        $body = $this->handle($cachePath);

        self::assertSame(200, http_response_code());
        self::assertFileExists($this->cacheDir . '/' . $cachePath);
        self::assertSame(file_get_contents($this->cacheDir . '/' . $cachePath), $body);
        $image = new \Imagick($this->cacheDir . '/' . $cachePath);
        self::assertGreaterThan(1, $image->getNumberImages());
        $canvas = $image->coalesceImages();
        foreach ($canvas as $frame) {
            self::assertSame(640, $frame->getImageWidth());
        }
        $canvas->clear();
        $image->clear();
        self::assertFileDoesNotExist($this->cacheDir . '/' . $cachePath . '.lock');
        self::assertFileExists(\rex_path::addonData(Config::ADDON, 'animated-encode.lock'));
        self::assertSame($body, $this->handle($cachePath));
    }

    public function testServesLegacyName(): void
    {
        if (!extension_loaded('imagick') || \Imagick::queryFormats('WEBP') === []) {
            self::markTestSkipped('Animated WebP encoding needs the Imagick WebP delegate.');
        }
        copy(__DIR__ . '/../_fixtures/animated-3frame.gif', $this->mediaDir . '/animation.gif');

        $body = $this->handle('animation.gif/animated.webp');

        self::assertSame(200, http_response_code());
        self::assertSame(file_get_contents($this->cacheDir . '/animation.gif/animated.webp'), $body);
        $image = new \Imagick($this->cacheDir . '/animation.gif/animated.webp');
        self::assertSame(3, $image->getNumberImages());
        self::assertSame(64, $image->getImageWidth());
        $image->clear();
    }

    private function stageAnimatedMetadata(int $frames, int $width = 1600, int $height = 900): void
    {
        copy(__DIR__ . '/../_fixtures/animated-3frame.gif', $this->mediaDir . '/animation.gif');
        $source = (new MediapoolSourceFactory())->resolve('animation.gif');
        $metaPath = MetadataReader::metaCachePath($source);
        @mkdir(dirname($metaPath), 0777, true);
        file_put_contents($metaPath, json_encode([
            'width' => $width, 'height' => $height, 'mime' => 'image/gif', 'source_format' => 'gif',
            'focal' => null, 'is_animated' => true, 'frames' => $frames, 'failed' => false,
        ]));
    }

    #[DataProvider('animatedRequestStates')]
    public function testOverBudgetStreamsOriginalUnlessVariantCached(string $filename, bool $cached): void
    {
        // 1600 × 900 × 380 = 547.2 Mpx, including a previously generated cache hit.
        $this->stageAnimatedMetadata(380);
        $path = 'animation.gif/' . $filename;
        if ($cached) {
            @mkdir(dirname($this->cacheDir . '/' . $path), 0777, true);
            file_put_contents($this->cacheDir . '/' . $path, 'previous-animation');
        }

        $body = $this->handle($path);

        if ($cached) {
            self::assertNotSame(404, http_response_code());
            self::assertSame('previous-animation', file_get_contents($this->cacheDir . '/' . $path));
            return;
        }
        self::assertSame(200, http_response_code());
        self::assertSame(file_get_contents($this->mediaDir . '/animation.gif'), $body);
        self::assertFileDoesNotExist($this->cacheDir . '/' . $path);
        self::assertFileDoesNotExist($this->cacheDir . '/' . $path . '.lock');
    }

    public static function animatedRequestStates(): array
    {
        return [['animated.webp', false], ['animated-640.webp', false], ['animated.webp', true], ['animated-640.webp', true]];
    }

    #[DataProvider('invalidWidths')]
    public function testWidthNotInPoolStreamsOriginal(int $width): void
    {
        $this->stageAnimatedMetadata(3);
        $path = 'animation.gif/animated-' . $width . '.webp';

        $body = $this->handle($path);

        self::assertSame(200, http_response_code());
        self::assertSame(file_get_contents($this->mediaDir . '/animation.gif'), $body);
    }

    public static function invalidWidths(): array
    {
        return [[0], [400], [2000]];
    }

    #[DataProvider('nonCanonicalNames')]
    public function testNonCanonicalNameStreamsOriginal(string $filename): void
    {
        $this->stageAnimatedMetadata(3);

        $body = $this->handle('animation.gif/' . $filename);

        self::assertSame(200, http_response_code());
        self::assertSame(file_get_contents($this->mediaDir . '/animation.gif'), $body);
        self::assertFileDoesNotExist($this->cacheDir . '/animation.gif/' . $filename);
    }

    public static function nonCanonicalNames(): array
    {
        return [['animated-1600.webp'], ['animated-0640.webp']];
    }

    #[DataProvider('unencodableMetadata')]
    public function testUnencodableSourceStreamsOriginal(int $frames, int $width, int $height): void
    {
        $this->stageAnimatedMetadata($frames, $width, $height);
        $body = $this->handle('animation.gif/animated.webp');

        self::assertSame(200, http_response_code());
        self::assertSame(file_get_contents($this->mediaDir . '/animation.gif'), $body);
    }

    public function testBusyEncoderStreamsOriginal(): void
    {
        $this->stageAnimatedMetadata(3);
        $lockPath = \rex_path::addonData(Config::ADDON, 'animated-encode.lock');
        @mkdir(dirname($lockPath), 0777, true);
        $lock = fopen($lockPath, 'c');
        flock($lock, LOCK_EX);
        try {
            $body = $this->handle('animation.gif/animated.webp');
            self::assertSame(200, http_response_code());
            self::assertSame(file_get_contents($this->mediaDir . '/animation.gif'), $body);
            self::assertFileDoesNotExist($this->cacheDir . '/animation.gif/animated.webp');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function testLegacyAnimatedTransformsStreamOriginalWithoutGlide(): void
    {
        foreach (['gif', 'webp'] as $format) {
            $filename = 'animation.' . $format;
            copy(__DIR__ . '/../_fixtures/animated-3frame.' . $format, $this->mediaDir . '/' . $filename);
            $path = $filename . '/jpg-32-80.jpg';

            $body = $this->handle($path);

            self::assertSame(200, http_response_code());
            self::assertSame(file_get_contents($this->mediaDir . '/' . $filename), $body);
            self::assertFileDoesNotExist($this->cacheDir . '/' . $path);
            self::assertFileDoesNotExist($this->cacheDir . '/' . $path . '.lock');
        }
    }

    public function testExternalAnimatedTransformStreamsOriginalWithoutGlide(): void
    {
        $url = 'http://1.1.1.1/animation.webp';
        $hash = \Ynamite\Media\Source\ExternalSource::hashFor($url);
        $origin = \Ynamite\Media\Source\ExternalManifest::originPath($hash);
        @mkdir(dirname($origin), 0777, true);
        copy(__DIR__ . '/../_fixtures/animated-3frame.webp', $origin);
        \Ynamite\Media\Source\ExternalManifest::write($hash, [
            'url' => $url, 'etag' => null, 'lastModified' => time(), 'fetchedAt' => time(), 'ttl' => 86_400,
        ]);
        $path = '_external/' . $hash . '/jpg-32-80.jpg';

        $body = $this->handle($path);

        self::assertSame(200, http_response_code());
        self::assertSame(file_get_contents($origin), $body);
        self::assertFileDoesNotExist($this->cacheDir . '/' . $path);
    }

    public function testBadSignatureNeverStreamsOriginalAnimation(): void
    {
        $this->stageAnimatedMetadata(3);
        $_GET = ['p' => 'animation.gif/animated.webp', 's' => 'bad-signature'];
        ob_start();
        Endpoint::handle();
        $body = ob_get_clean();

        self::assertSame(403, http_response_code());
        self::assertSame('Forbidden', $body);
    }

    public function testSignedTraversalNeverStreamsOriginal(): void
    {
        mkdir($this->tmpBase . '/private');
        copy(__DIR__ . '/../_fixtures/animated-3frame.webp', $this->tmpBase . '/private/animation.webp');

        $body = $this->handle('../private/animation.webp/animated.webp');

        self::assertSame(404, http_response_code());
        self::assertSame('Not found', $body);
    }

    public function testSignedParentSegmentInsideMediaIsRejected(): void
    {
        mkdir($this->mediaDir . '/gallery');
        copy(__DIR__ . '/../_fixtures/animated-3frame.webp', $this->mediaDir . '/animation.webp');

        $body = $this->handle('gallery/../animation.webp/animated.webp');

        self::assertSame(404, http_response_code());
        self::assertSame('Not found', $body);
    }

    public function testSignedTraversalNeverStreamsExistingFileOutsideCache(): void
    {
        foreach (['/outside/animated-700.webp', '/outside/hero.jpg/avif-640.avif'] as $target) {
            @mkdir(dirname($this->tmpBase . '/assets/addons/massif_media' . $target), 0777, true);
            file_put_contents($this->tmpBase . '/assets/addons/massif_media' . $target, 'secret-bytes');

            $body = $this->handle('..' . $target);

            self::assertSame(404, http_response_code());
            self::assertSame('Not found', $body);
        }
    }

    public function testSignedSymlinkOutsideMediaNeverStreamsOriginal(): void
    {
        mkdir($this->tmpBase . '/media-private');
        copy(__DIR__ . '/../_fixtures/animated-3frame.webp', $this->tmpBase . '/media-private/animation.webp');
        $link = $this->mediaDir . '/animation.webp';
        symlink($this->tmpBase . '/media-private/animation.webp', $link);
        try {
            $body = $this->handle('animation.webp/animated.webp');

            self::assertSame(404, http_response_code());
            self::assertSame('Not found', $body);
        } finally {
            unlink($link);
        }
    }

    public function testSignedNonImageNeverStreamsOriginal(): void
    {
        file_put_contents($this->mediaDir . '/private.webp', 'private configuration bytes');

        $body = $this->handle('private.webp/animated.webp');

        self::assertSame(404, http_response_code());
        self::assertSame('Not found', $body);
    }

    public function testExternalOriginSymlinkCannotEscapeItsBucket(): void
    {
        $url = 'https://example.com/animation.webp';
        $hash = \Ynamite\Media\Source\ExternalSource::hashFor($url);
        $other = \Ynamite\Media\Source\ExternalManifest::originPath('0123456789abcdef');
        @mkdir(dirname($other), 0777, true);
        copy(__DIR__ . '/../_fixtures/animated-3frame.webp', $other);
        \Ynamite\Media\Source\ExternalManifest::write($hash, [
            'url' => $url, 'etag' => null, 'lastModified' => time(), 'fetchedAt' => time(), 'ttl' => 86_400,
        ]);
        $origin = \Ynamite\Media\Source\ExternalManifest::originPath($hash);
        symlink($other, $origin);
        try {
            $body = $this->handle('_external/' . $hash . '/animated.webp');

            self::assertSame(404, http_response_code());
            self::assertSame('Not found', $body);
        } finally {
            unlink($origin);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWebpWithoutImagickStreamsOriginalForAnimatedAndGlideRequests(): void
    {
        require __DIR__ . '/../_stubs/no-imagick.php';
        copy(__DIR__ . '/../_fixtures/animated-3frame.webp', $this->mediaDir . '/animation.webp');
        foreach (['animated.webp', 'jpg-32-80.jpg'] as $variant) {
            $path = 'animation.webp/' . $variant;
            $body = $this->handle($path);

            self::assertSame(200, http_response_code());
            self::assertSame(file_get_contents($this->mediaDir . '/animation.webp'), $body);
            self::assertFileDoesNotExist($this->cacheDir . '/' . $path);
        }
    }

    public function testUnavailableWebpStreamsOriginal(): void
    {
        $this->stageAnimatedMetadata(3);
        $capability = new \ReflectionProperty(Config::class, 'serverFormatCapability');
        $previous = $capability->getValue();
        $capability->setValue(null, ['webp' => false]);
        try {
            $body = $this->handle('animation.gif/animated.webp');
            self::assertSame(200, http_response_code());
            self::assertSame(file_get_contents($this->mediaDir . '/animation.gif'), $body);
            self::assertFileDoesNotExist($this->cacheDir . '/animation.gif/animated.webp');
        } finally {
            $capability->setValue(null, $previous);
        }
    }

    public function testUnconfirmedKeyRejectsForgedRequest(): void
    {
        $this->stageAnimatedMetadata(3);
        \rex_config::set(Config::ADDON, Config::KEY_SIGN_KEY, '');
        Config::$signKeyPersister = static fn (string $candidate): string => '';
        $_GET = ['p' => 'animation.gif/animated.webp', 's' => hash_hmac('sha256', 'animation.gif/animated.webp', '')];
        ob_start();
        try {
            Endpoint::handle();
            $body = ob_get_contents();
        } finally {
            ob_end_clean();
            Config::$signKeyPersister = null;
        }
        self::assertSame(403, http_response_code());
        self::assertSame('Forbidden', $body);
    }

    public static function unencodableMetadata(): array
    {
        return [[0, 1600, 900], [3, 0, 0]];
    }

    #[DataProvider('glidePathShapes')]
    public function testAnimatedRoutingPreservesGlideShapes(string $filename, bool $filtered): void
    {
        $filters = ['bri' => 10];
        if ($filtered) {
            $filename = str_replace('{hash}', CacheKeyBuilder::hashFilterParams($filters), $filename);
            $_GET['f'] = CacheKeyBuilder::encodeFilterBlob($filters);
        }
        // Animated-looking source names and format tokens must stay on Glide's disk fastpath.
        $path = 'gallery/animated-640.webp/' . $filename;
        @mkdir(dirname($this->cacheDir . '/' . $path), 0777, true);
        file_put_contents($this->cacheDir . '/' . $path, 'glide-cache');
        $_GET['p'] = $path;
        $_GET['s'] = Signature::sign($path, $_GET['f'] ?? null, self::KEY);
        ob_start();
        try {
            Endpoint::handle();
        } finally {
            $body = (string) ob_get_clean();
        }

        self::assertSame(200, http_response_code());
        self::assertSame('glide-cache', $body);
    }

    public static function glidePathShapes(): array
    {
        return [
            ['webp-640-75.webp', false],
            ['webp-640-480-cover-25-75-75.webp', false],
            ['webp-640-75-f{hash}.webp', true],
            ['webp-640-480-contain-75-f{hash}.webp', true],
            ['animated-640-75.webp', false],
        ];
    }

    #[DataProvider('malformedAnimatedNames')]
    public function testAnimatedRoutingMatchesOnlyExactFinalFilename(string $filename): void
    {
        $this->handle('animation.gif/' . $filename);
        self::assertSame(400, http_response_code());
    }

    public static function malformedAnimatedNames(): array
    {
        return [['notanimated-640.webp'], ['animated--640.webp'], ['animated-x.webp'], ['animated-640.webp.extra'], ['animated-640.webp/trailing'], ["animated-640.webp\n"]];
    }
}
