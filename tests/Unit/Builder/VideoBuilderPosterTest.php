<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Builder;

use PHPUnit\Framework\TestCase;
use rex_logger;
use rex_path;
use Ynamite\Media\Builder\VideoBuilder;

/**
 * Verifies VideoBuilder's poster-validation behaviour. Browsers handle a
 * broken `<video poster>` URL inconsistently — WebKit/Blink leave the video
 * element at 0×0 until metadata loads when no width/height are set, which
 * collapses the layout. We avoid that by never emitting a poster attr we
 * know to be broken (bare-filename mediapool reference that doesn't exist).
 * Anything we can't cheaply verify (URLs, absolute paths, data URIs) passes
 * through unchanged.
 */
final class VideoBuilderPosterTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/massif_media_poster_' . uniqid('', true);
        @mkdir($this->tmpDir . '/media', 0777, true);
        // The video src has to be readable for VideoBuilder::render() to
        // reach the poster-emission branch — touch a fake mp4.
        file_put_contents($this->tmpDir . '/media/clip.mp4', '');
        rex_path::_setBase($this->tmpDir);
        rex_logger::_reset();
    }

    protected function tearDown(): void
    {
        \rex_dir::delete($this->tmpDir, true);
        rex_logger::_reset();
    }

    public function testDropsMissingMediapoolPoster(): void
    {
        $html = (new VideoBuilder('clip.mp4'))->poster('nonexistent.jpg')->render();

        self::assertStringNotContainsString('poster=', $html);
        self::assertCount(1, rex_logger::$logged);
        self::assertStringContainsString(
            'poster not found: nonexistent.jpg',
            rex_logger::$logged[0]->getMessage(),
        );
    }

    public function testKeepsValidMediapoolPoster(): void
    {
        // Touch the poster file so it's readable.
        file_put_contents($this->tmpDir . '/media/still.jpg', '');

        $html = (new VideoBuilder('clip.mp4'))->poster('still.jpg')->render();

        self::assertStringContainsString('poster="still.jpg"', $html);
        self::assertCount(0, rex_logger::$logged);
    }

    public function testPassesThroughHttpUrl(): void
    {
        $html = (new VideoBuilder('clip.mp4'))
            ->poster('https://cdn.example.com/still.jpg')
            ->render();

        self::assertStringContainsString('poster="https://cdn.example.com/still.jpg"', $html);
        self::assertCount(0, rex_logger::$logged);
    }

    public function testPassesThroughHttpsUrl(): void
    {
        // Tested separately because the `://` check is the gate, not just
        // the http scheme. Anything with `://` passes through.
        $html = (new VideoBuilder('clip.mp4'))
            ->poster('http://example.com/still.jpg')
            ->render();

        self::assertStringContainsString('poster="http://example.com/still.jpg"', $html);
        self::assertCount(0, rex_logger::$logged);
    }

    public function testPassesThroughAbsolutePath(): void
    {
        $html = (new VideoBuilder('clip.mp4'))
            ->poster('/assets/addons/massif_media/cache/hero.jpg/webp-1280-50.webp?s=abc')
            ->render();

        self::assertStringContainsString(
            'poster="/assets/addons/massif_media/cache/hero.jpg/webp-1280-50.webp?s=abc"',
            $html,
        );
        self::assertCount(0, rex_logger::$logged);
    }

    public function testPassesThroughDataUri(): void
    {
        // Tiny 1×1 transparent PNG data URI — synthetic but the validator
        // shouldn't care about validity, just that it starts with `data:`.
        $dataUri = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=';

        $html = (new VideoBuilder('clip.mp4'))->poster($dataUri)->render();

        self::assertStringContainsString('poster="' . htmlspecialchars($dataUri, ENT_QUOTES) . '"', $html);
        self::assertCount(0, rex_logger::$logged);
    }

    public function testPassesThroughProtocolRelativeUrl(): void
    {
        // `//cdn.example.com/foo.jpg` is technically an "absolute path" (starts
        // with `/`) but is also URL-like. The leading-`/` check covers it.
        $html = (new VideoBuilder('clip.mp4'))
            ->poster('//cdn.example.com/still.jpg')
            ->render();

        self::assertStringContainsString('poster="//cdn.example.com/still.jpg"', $html);
        self::assertCount(0, rex_logger::$logged);
    }
}
