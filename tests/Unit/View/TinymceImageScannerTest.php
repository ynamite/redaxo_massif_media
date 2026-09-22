<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\View;

use PHPUnit\Framework\TestCase;
use rex_config;
use Ynamite\Media\Config;
use Ynamite\Media\View\TinymceImageScanner;

final class TinymceImageScannerTest extends TestCase
{
    protected function setUp(): void
    {
        rex_config::_reset();
        rex_config::set('tinymce', 'media_upload_settings', ['upload_media_manager_type' => '']);
        TinymceImageScanner::setSizes(null);
    }

    public function testNoImgTagReturnsOriginal(): void
    {
        $html = '<p>Plain content.</p>';
        self::assertSame($html, TinymceImageScanner::scan($html, fn () => 'X'));
    }

    public function testOtherMediaTypesAreLeftAlone(): void
    {
        $html = '<p><img src="/media/hero/foo.jpg" alt="x"></p><img src="/media/foo.jpg">';
        self::assertSame($html, TinymceImageScanner::scan($html, fn () => 'X'));
    }

    public function testMatchesTinyWithoutAnyTinymceConfig(): void
    {
        rex_config::set('tinymce', 'media_upload_settings', null);
        $html = '<p><img src="/media/tiny/foo.jpg" alt="x"></p>';
        self::assertSame('<p>X</p>', TinymceImageScanner::scan($html, fn () => 'X'));
    }

    public function testMatchesConfiguredUploadTypeToo(): void
    {
        rex_config::set('tinymce', 'media_upload_settings', ['upload_media_manager_type' => 'editor']);
        $html = '<img src="/media/editor/a.jpg"><img src="/media/tiny/b.jpg"><img src="/media/other/c.jpg">';
        self::assertSame('XX<img src="/media/other/c.jpg">', TinymceImageScanner::scan($html, fn () => 'X'));
    }

    public function testReplacesImgAndKeepsWrapper(): void
    {
        $html = '<p><img src="/media/tiny/standort-karte.jpg" alt="Karte (&copy; OSM &amp; Co)"></p>';
        $calls = [];
        $out = TinymceImageScanner::scan($html, function (string $file, ?string $alt, ?string $sizes) use (&$calls): string {
            $calls[] = [$file, $alt, $sizes];
            return '<picture>P</picture>';
        });
        self::assertSame('<p><picture>P</picture></p>', $out);
        self::assertSame([['standort-karte.jpg', 'Karte (© OSM & Co)', null]], $calls);
    }

    public function testUrlDecodesAndStripsQueryFromFilename(): void
    {
        $html = '<img alt="" src="/media/tiny/caf%C3%A9%20bild.jpg?v=3" class="c">';
        $seen = null;
        TinymceImageScanner::scan($html, function (string $file, ?string $alt) use (&$seen): string {
            $seen = [$file, $alt];
            return 'X';
        });
        self::assertSame(['café bild.jpg', null], $seen);
    }

    public function testKeepsOriginalWhenRenderThrowsOrIsEmpty(): void
    {
        $html = '<img src="/media/tiny/a.jpg"><img src="/media/tiny/b.jpg">';
        $out = TinymceImageScanner::scan($html, function (string $file): string {
            if ($file === 'a.jpg') {
                throw new \RuntimeException('boom');
            }
            return '';
        });
        self::assertSame($html, $out);
    }

    public function testDefaultRendererFailsOpenWithoutMediapool(): void
    {
        $html = '<img src="/media/tiny/does-not-exist.jpg" alt="x">';
        self::assertSame($html, TinymceImageScanner::scan($html));
    }

    public function testSizesResolutionOrder(): void
    {
        self::assertNull(TinymceImageScanner::sizes());

        rex_config::set(Config::ADDON, Config::KEY_TINYMCE_SIZES, '100vw');
        self::assertSame('100vw', TinymceImageScanner::sizes());

        TinymceImageScanner::setSizes('50vw');
        self::assertSame('50vw', TinymceImageScanner::sizes());

        TinymceImageScanner::setSizes(null);
        self::assertSame('100vw', TinymceImageScanner::sizes());
    }

    public function testSetSizesIsPassedToRenderer(): void
    {
        TinymceImageScanner::setSizes('(min-width: 768px) 50vw, 100vw');
        $seen = null;
        TinymceImageScanner::scan('<img src="/media/tiny/a.jpg">', function (string $f, ?string $a, ?string $sizes) use (&$seen): string {
            $seen = $sizes;
            return 'X';
        });
        self::assertSame('(min-width: 768px) 50vw, 100vw', $seen);
    }

    public function testConfigToggleDefaultsOff(): void
    {
        self::assertFalse(Config::tinymcePictureEnabled());
        rex_config::set(Config::ADDON, Config::KEY_TINYMCE_PICTURE, '|1|');
        self::assertTrue(Config::tinymcePictureEnabled());
    }
}
