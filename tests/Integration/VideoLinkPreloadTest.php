<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Integration;

use PHPUnit\Framework\TestCase;
use Ynamite\Media\Builder\VideoBuilder;
use Ynamite\Media\Pipeline\Preloader;
use Ynamite\Media\Video;

/**
 * Exercises the linkPreload feature end-to-end: VideoBuilder queues raw
 * preload entries via Preloader::queueLink, the OUTPUT_FILTER drains them.
 * Asserts the link shapes (as=video / as=image), MIME mapping, and that
 * non-preloadable poster references (bare filenames) are skipped silently.
 */
final class VideoLinkPreloadTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/massif_media_linkpreload_' . uniqid('', true);
        @mkdir($this->tmpDir . '/media', 0777, true);
        \rex_path::_setBase($this->tmpDir);
        \rex_logger::_reset();
        Preloader::reset();
    }

    protected function tearDown(): void
    {
        \rex_dir::delete($this->tmpDir, true);
        \rex_logger::_reset();
        Preloader::reset();
    }

    private function touch(string $path): void
    {
        file_put_contents($this->tmpDir . '/media/' . $path, '');
    }

    public function testLinkPreloadEmitsVideoLinkWithMp4Mime(): void
    {
        $this->touch('clip.mp4');

        Video::render(src: 'clip.mp4', linkPreload: true);
        $html = Preloader::drain();

        self::assertStringContainsString('<link rel="preload"', $html);
        self::assertStringContainsString('as="video"', $html);
        self::assertStringContainsString('type="video/mp4"', $html);
    }

    public function testLinkPreloadEmitsBothVideoAndPosterWhenPosterIsAbsolutePath(): void
    {
        $this->touch('clip.mp4');

        Video::render(
            src: 'clip.mp4',
            poster: '/media/poster.jpg',
            linkPreload: true,
        );
        $html = Preloader::drain();

        self::assertSame(
            2,
            substr_count($html, '<link rel="preload"'),
            'one for the video src, one for the poster',
        );
        self::assertStringContainsString('as="video"', $html);
        self::assertStringContainsString('as="image"', $html);
        self::assertStringContainsString('href="/media/poster.jpg"', $html);
    }

    public function testLinkPreloadEmitsImageLinkForFullUrlPoster(): void
    {
        $this->touch('clip.mp4');

        Video::render(
            src: 'clip.mp4',
            poster: 'https://cdn.example.com/poster.jpg',
            linkPreload: true,
        );
        $html = Preloader::drain();

        self::assertStringContainsString('href="https://cdn.example.com/poster.jpg"', $html);
        self::assertStringContainsString('as="image"', $html);
    }

    public function testLinkPreloadSkipsBareFilenamePoster(): void
    {
        // Bare-filename posters (even valid Mediapool refs) get rendered as
        // <video poster="hero.jpg">, which the browser resolves relative to
        // the page URL — not what the user wants. The recipe is
        // Image::url() / REX_PIC[as=url] for the responsive case. Emitting
        // a preload pointing at the Mediapool URL would create an
        // inconsistent fetch that the browser can't dedupe with the
        // (wrong) poster URL it actually loads.
        $this->touch('clip.mp4');
        $this->touch('still.jpg');

        Video::render(
            src: 'clip.mp4',
            poster: 'still.jpg',
            linkPreload: true,
        );
        $html = Preloader::drain();

        self::assertSame(1, substr_count($html, '<link rel="preload"'));
        self::assertStringContainsString('as="video"', $html);
        self::assertStringNotContainsString('as="image"', $html);
    }

    public function testLinkPreloadSkipsMissingPoster(): void
    {
        // Missing poster → validatePoster drops the attribute entirely AND
        // skips the link preload. Only the video src gets preloaded.
        $this->touch('clip.mp4');

        Video::render(
            src: 'clip.mp4',
            poster: 'nonexistent.jpg',
            linkPreload: true,
        );
        $html = Preloader::drain();

        self::assertSame(1, substr_count($html, '<link rel="preload"'));
        self::assertStringContainsString('as="video"', $html);
        self::assertStringNotContainsString('as="image"', $html);
        // The dropped poster also got logged.
        self::assertNotEmpty(\rex_logger::$logged);
    }

    public function testLinkPreloadEmitsDataUriPosterDirectly(): void
    {
        $this->touch('clip.mp4');
        $dataUri = 'data:image/png;base64,iVBORw0KGgo=';

        Video::render(
            src: 'clip.mp4',
            poster: $dataUri,
            linkPreload: true,
        );
        $html = Preloader::drain();

        self::assertStringContainsString('href="' . htmlspecialchars($dataUri, ENT_QUOTES) . '"', $html);
    }

    public function testNoLinkPreloadEmittedByDefault(): void
    {
        // Without linkPreload: true, the existing behaviour holds —
        // <video preload="metadata"> attribute, no <link rel="preload">.
        $this->touch('clip.mp4');

        Video::render(src: 'clip.mp4', poster: '/media/poster.jpg');
        $html = Preloader::drain();

        self::assertSame('', $html);
    }

    public function testWebmExtensionMapsToWebmMime(): void
    {
        $this->touch('clip.webm');

        (new VideoBuilder('clip.webm'))->linkPreload()->render();
        $html = Preloader::drain();

        self::assertStringContainsString('type="video/webm"', $html);
    }

    public function testMovExtensionMapsToQuickTimeMime(): void
    {
        // Common gotcha: video/mov is NOT a registered MIME and Safari's
        // preload scheduler ignores it. Must be video/quicktime.
        $this->touch('clip.mov');

        (new VideoBuilder('clip.mov'))->linkPreload()->render();
        $html = Preloader::drain();

        self::assertStringContainsString('type="video/quicktime"', $html);
    }

    public function testUnknownExtensionOmitsTypeAttribute(): void
    {
        // Unknown ext → no type emitted. Browsers accept the preload without
        // it; emitting a guessed-wrong MIME would make the preload silently
        // ignored.
        $this->touch('clip.weird');

        (new VideoBuilder('clip.weird'))->linkPreload()->render();
        $html = Preloader::drain();

        self::assertStringContainsString('as="video"', $html);
        self::assertStringNotContainsString('type=', $html);
    }
}
