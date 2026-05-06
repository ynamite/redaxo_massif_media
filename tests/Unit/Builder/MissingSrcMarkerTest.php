<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Builder;

use PHPUnit\Framework\TestCase;
use rex;
use rex_logger;
use rex_path;
use Ynamite\Media\Builder\ImageBuilder;
use Ynamite\Media\Builder\VideoBuilder;

/**
 * Verifies the rex::isDebugMode()-gated <!-- src not found --> HTML comment that
 * Image- and VideoBuilder emit when their source can't be read. In production
 * (debug off) both builders return ''; in debug mode the editor sees the
 * typo'd filename in the page source instead of an empty render.
 */
final class MissingSrcMarkerTest extends TestCase
{
    protected function setUp(): void
    {
        // Point rex_path at a temp dir that has no media in it — every
        // resolve() will hit the missing-src branch.
        rex_path::_setBase(sys_get_temp_dir() . '/massif_media_missingsrc_' . uniqid('', true));
        rex_logger::_reset();
    }

    protected function tearDown(): void
    {
        rex::_setDebug(false);
        rex_logger::_reset();
    }

    // --- Image -----------------------------------------------------------

    public function testImageBuilderReturnsEmptyInProduction(): void
    {
        rex::_setDebug(false);

        $html = (new ImageBuilder('does-not-exist.jpg'))->render();

        self::assertSame('', $html);
        self::assertCount(1, rex_logger::$logged, 'Missing src is always logged.');
    }

    public function testImageBuilderEmitsCommentInDebug(): void
    {
        rex::_setDebug(true);

        $html = (new ImageBuilder('typo-image.jpg'))->render();

        self::assertSame('<!-- massif_media: src not found "typo-image.jpg" -->', $html);
        self::assertCount(1, rex_logger::$logged);
    }

    public function testImageBuilderEscapesFilenameInComment(): void
    {
        rex::_setDebug(true);

        $html = (new ImageBuilder('a"--><script>x</script>.jpg'))->render();

        // No literal --> appears mid-comment, no <script> survives unescaped.
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&quot;', $html);
    }

    // --- Video -----------------------------------------------------------

    public function testVideoBuilderReturnsEmptyInProductionForMissingFile(): void
    {
        rex::_setDebug(false);

        $html = (new VideoBuilder('does-not-exist.mp4'))->render();

        self::assertSame('', $html);
        self::assertCount(1, rex_logger::$logged, 'Video missing src is now logged (parity with image path).');
    }

    public function testVideoBuilderEmitsCommentInDebugForMissingFile(): void
    {
        rex::_setDebug(true);

        $html = (new VideoBuilder('typo-video.mp4'))->render();

        self::assertSame('<!-- massif_media: src not found "typo-video.mp4" -->', $html);
        self::assertCount(1, rex_logger::$logged);
    }

    public function testVideoBuilderEmptyFilenameProducesEmptyMarkerInProd(): void
    {
        rex::_setDebug(false);

        $html = (new VideoBuilder(''))->render();

        self::assertSame('', $html);
        self::assertCount(0, rex_logger::$logged, 'Empty filename short-circuits before the readable check.');
    }

    public function testVideoBuilderEmptyFilenameInDebugStillEmitsMarker(): void
    {
        rex::_setDebug(true);

        $html = (new VideoBuilder(''))->render();

        self::assertSame('<!-- massif_media: src not found "" -->', $html);
    }
}
