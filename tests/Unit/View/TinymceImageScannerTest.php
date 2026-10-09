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
        $out = TinymceImageScanner::scan($html, function (string $file, ?string $alt, ?int $width, ?string $sizes, ?string $class) use (&$calls): string {
            $calls[] = [$file, $alt, $width, $sizes, $class];
            return '<picture>P</picture>';
        });
        self::assertSame('<p><picture>P</picture></p>', $out);
        self::assertSame([['standort-karte.jpg', 'Karte (© OSM & Co)', null, null, null]], $calls);
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
        TinymceImageScanner::scan('<img src="/media/tiny/a.jpg" width="300">', function (string $f, ?string $a, ?int $w, ?string $sizes) use (&$seen): string {
            $seen = $sizes;
            return 'X';
        });
        self::assertSame('(min-width: 768px) 50vw, 100vw', $seen);
    }

    public function testWidthFromAttributeIsPassedAndHeightIgnored(): void
    {
        self::assertSame([300, 'auto, (max-width: 300px) 100vw, 300px'], $this->widthAndSizes('<img src="/media/tiny/a.jpg" width="300" height="200">'));
    }

    public function testWidthFromStyleWhenNoAttribute(): void
    {
        self::assertSame(420, $this->widthAndSizes('<img style="float: left; WIDTH:420px; height: 280px" src="/media/tiny/a.jpg">')[0]);
        self::assertSame(250, $this->widthAndSizes("<img src='/media/tiny/a.jpg' style='max-width: 900px; width: 250.4px'>")[0]);
    }

    public function testWidthAttributeWinsOverStyle(): void
    {
        self::assertSame(300, $this->widthAndSizes('<img src="/media/tiny/a.jpg" width="300" style="width: 500px">')[0]);
    }

    public function testNonPixelWidthsAreIgnored(): void
    {
        foreach ([
            '<img src="/media/tiny/a.jpg" width="50%">',
            '<img src="/media/tiny/a.jpg" width="0">',
            '<img src="/media/tiny/a.jpg" style="width: 50%">',
            '<img src="/media/tiny/a.jpg" style="width: 20em">',
            '<img src="/media/tiny/a.jpg" style="width: auto">',
            '<img src="/media/tiny/a.jpg" data-width="300" style="min-width: 300px">',
            '<img src="/media/tiny/a.jpg" height="200">',
        ] as $html) {
            self::assertSame([null, null], $this->widthAndSizes($html), $html);
        }
    }

    public function testWidthDerivedSizesBeatConfigButNotOverride(): void
    {
        rex_config::set(Config::ADDON, Config::KEY_TINYMCE_SIZES, '100vw');
        self::assertSame('auto, (max-width: 300px) 100vw, 300px', $this->widthAndSizes('<img src="/media/tiny/a.jpg" width="300">')[1]);
        self::assertSame('100vw', $this->widthAndSizes('<img src="/media/tiny/a.jpg">')[1]);

        TinymceImageScanner::setSizes('50vw');
        self::assertSame('50vw', $this->widthAndSizes('<img src="/media/tiny/a.jpg" width="300">')[1]);
    }

    public function testClassIsPassedAndStyleDropped(): void
    {
        $seen = null;
        $out = TinymceImageScanner::scan('<img class="rounded left" style="float:left" src="/media/tiny/a.jpg">', function (string $f, ?string $a, ?int $w, ?string $s, ?string $class) use (&$seen): string {
            $seen = $class;
            return '<picture>P</picture>';
        });
        self::assertSame('rounded left', $seen);
        self::assertSame('<picture>P</picture>', $out);
        self::assertNull($this->classOf('<img class="" src="/media/tiny/a.jpg">'));
    }

    /** @return array{0: ?int, 1: ?string} */
    private function widthAndSizes(string $html): array
    {
        $seen = null;
        TinymceImageScanner::scan($html, function (string $f, ?string $a, ?int $width, ?string $sizes) use (&$seen): string {
            $seen = [$width, $sizes];
            return 'X';
        });
        return $seen;
    }

    private function classOf(string $html): ?string
    {
        $seen = 'unset';
        TinymceImageScanner::scan($html, function (string $f, ?string $a, ?int $w, ?string $s, ?string $class) use (&$seen): string {
            $seen = $class;
            return 'X';
        });
        return $seen;
    }

    public function testConfigToggleDefaultsOff(): void
    {
        self::assertFalse(Config::tinymcePictureEnabled());
        rex_config::set(Config::ADDON, Config::KEY_TINYMCE_PICTURE, '|1|');
        self::assertTrue(Config::tinymcePictureEnabled());
    }
}
