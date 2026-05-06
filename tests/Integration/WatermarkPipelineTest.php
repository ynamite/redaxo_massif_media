<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Integration;

use PHPUnit\Framework\TestCase;
use rex_config;
use rex_path;
use Ynamite\Media\Config;
use Ynamite\Media\Glide\Server;

/**
 * Locks in the mediapool watermark code path. Without this regression test,
 * a future change to {@see Server::create()}'s watermarks-FS root or to
 * {@see \Ynamite\Media\Pipeline\WatermarkResolver}'s mediapool prefix would
 * silently strand all `mark="logo.png"` calls — `Watermark::run()` returns
 * the unmodified image when the file isn't found, with no exception, and
 * the picture renders without a watermark (the user's exact bug report).
 */
final class WatermarkPipelineTest extends TestCase
{
    private string $tmpBase;

    protected function setUp(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('Watermark integration test needs Imagick.');
        }
        $this->tmpBase = sys_get_temp_dir() . '/massif_wmark_int_' . uniqid('', true);
        @mkdir($this->tmpBase . '/media', 0777, true);
        @mkdir($this->tmpBase . '/assets/addons/massif_media/cache', 0777, true);
        copy(__DIR__ . '/../_fixtures/landscape-800x600.jpg', $this->tmpBase . '/media/hero.jpg');
        copy(__DIR__ . '/../_fixtures/square-400x400.png', $this->tmpBase . '/media/logo.png');
        rex_path::_setBase($this->tmpBase);
        rex_config::set(Config::ADDON, Config::KEY_SIGN_KEY, 'integration-test-key');
    }

    protected function tearDown(): void
    {
        Server::clearActiveFilters();
        rex_config::_reset();
        \rex_dir::delete($this->tmpBase, true);
    }

    public function testMediapoolWatermarkChangesRenderedBytes(): void
    {
        // The translated path WatermarkResolver emits for `mark="logo.png"`.
        // Endpoint::translateMark applies the prefix in production; the
        // integration test calls Server::makeImage directly, so we simulate
        // the post-translation params here.
        $markFilters = [
            'mark' => 'media/logo.png',
            'markw' => 100,
            'markpos' => 'bottom-right',
            'markpad' => 20,
        ];

        Server::setActiveFilters($markFilters);
        try {
            $server = Server::create();
            $relWith = $server->makeImage('hero.jpg', array_merge(
                ['fm' => 'jpg', 'w' => 600, 'q' => 80],
                $markFilters,
            ));
            $bytesWith = $server->getCache()->read($relWith);
        } finally {
            Server::clearActiveFilters();
        }

        // Render the same source / size / quality WITHOUT the mark filter.
        $serverNoMark = Server::create();
        $relWithout = $serverNoMark->makeImage('hero.jpg', [
            'fm' => 'jpg', 'w' => 600, 'q' => 80,
        ]);
        $bytesWithout = $serverNoMark->getCache()->read($relWithout);

        // Different cache paths (filter hash on one side, none on the other).
        self::assertNotSame($relWith, $relWithout, 'mark filter must contribute a filter-hash segment to the cache path');
        self::assertMatchesRegularExpression('@-f[a-f0-9]{8}\.jpg$@', $relWith);

        // Watermark actually altered the pixels — the cheapest "did the
        // composite happen" signal we can rely on without pixel-level
        // comparison via Imagick.
        self::assertNotSame($bytesWith, $bytesWithout, 'mediapool watermark did not modify the rendered bytes');
    }
}
