<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Integration;

use PHPUnit\Framework\TestCase;
use Ynamite\Media\Config;
use Ynamite\Media\View\EditorContentScanner;

final class EditorContentScannerTest extends TestCase
{
    private string $tmpDir;
    private string $mediaDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/massif_media_scanner_int_' . uniqid('', true);
        $this->mediaDir = $this->tmpDir . '/media';
        @mkdir($this->mediaDir, 0777, true);
        \rex_path::_setBase($this->tmpDir);

        \rex_config::_reset();
        \rex_config::set(Config::ADDON, Config::KEY_SIGN_KEY, 'integration-test-key');
        \rex_config::set(Config::ADDON, Config::KEY_LQIP_ENABLED, 0);
        \rex_config::set(Config::ADDON, Config::KEY_COLOR_ENABLED, 0);
    }

    protected function tearDown(): void
    {
        \rex_dir::delete($this->tmpDir, true);
        \rex_config::_reset();
    }

    private function copyFixture(string $name, string $dest): void
    {
        $src = __DIR__ . '/../_fixtures/' . $name;
        if (!file_exists($src)) {
            self::markTestSkipped("Fixture {$name} missing");
        }
        copy($src, $this->mediaDir . '/' . $dest);
    }

    public function testScanReplacesRexPicLiteralWithPicture(): void
    {
        $this->copyFixture('landscape-800x600.jpg', 'hero.jpg');

        $html = '<p>REX_PIC[src="hero.jpg" alt="A view" width="400"]</p>';
        $result = EditorContentScanner::scan($html);

        self::assertStringContainsString('<picture>', $result);
        self::assertStringContainsString('</picture>', $result);
        self::assertStringContainsString('alt="A view"', $result);
        // The literal is gone.
        self::assertStringNotContainsString('REX_PIC[', $result);
        // <p> wrapper is preserved.
        self::assertStringStartsWith('<p>', $result);
        self::assertStringEndsWith('</p>', $result);
    }

    public function testScanDecodesHtmlEntitiesInAttributes(): void
    {
        // Editors save German umlauts as `&uuml;`. The decoded value must
        // round-trip into the rendered <img alt="..."> after htmlspecialchars
        // re-escapes — the resulting alt="" must NOT contain `&amp;uuml;`
        // (double-encoding) and must contain the umlaut equivalent.
        $this->copyFixture('landscape-800x600.jpg', 'hero.jpg');

        $html = '<p>REX_PIC[src="hero.jpg" alt="Aussicht &uuml;ber das Tal" width="400"]</p>';
        $result = EditorContentScanner::scan($html);

        self::assertStringContainsString('<picture>', $result);
        // htmlspecialchars(`Aussicht über das Tal`) = `Aussicht über das Tal`
        // (umlauts are valid in UTF-8 attribute values; only <, >, &, " get escaped).
        self::assertStringContainsString('alt="Aussicht über das Tal"', $result);
        self::assertStringNotContainsString('&amp;uuml;', $result);
    }

    public function testScanReplacesRexPicWithRatioAttribute(): void
    {
        $this->copyFixture('landscape-800x600.jpg', 'hero.jpg');

        $html = '<p>REX_PIC[src="hero.jpg" alt="x" width="600" ratio="16:9"]</p>';
        $result = EditorContentScanner::scan($html);

        self::assertStringContainsString('<picture>', $result);
        // ratio=16:9 on width=600 → height=337.5 → 338 (PHP int rounds half up)
        self::assertMatchesRegularExpression('/height="33[78]"/', $result);
    }

    public function testScanLeavesMixedContentUntouchedAroundReplacement(): void
    {
        $this->copyFixture('landscape-800x600.jpg', 'hero.jpg');

        $html = '<h1>Title</h1><p>Before REX_PIC[src="hero.jpg" alt="x" width="400"] after.</p><p>Other content.</p>';
        $result = EditorContentScanner::scan($html);

        self::assertStringContainsString('<h1>Title</h1>', $result);
        self::assertStringContainsString('Before <picture>', $result);
        self::assertStringContainsString('</picture> after.', $result);
        self::assertStringContainsString('<p>Other content.</p>', $result);
    }

    public function testScanReplacesMultipleRexPicTagsInSameInput(): void
    {
        $this->copyFixture('landscape-800x600.jpg', 'hero.jpg');
        $this->copyFixture('portrait-600x800.jpg', 'profile.jpg');

        $html = 'REX_PIC[src="hero.jpg" alt="Hero" width="400"] and REX_PIC[src="profile.jpg" alt="Profile" width="300"]';
        $result = EditorContentScanner::scan($html);

        // Two <picture> elements; no remaining REX_PIC literals.
        self::assertSame(2, substr_count($result, '<picture>'));
        self::assertStringNotContainsString('REX_PIC[', $result);
    }

    public function testScanCheapSkipsWithoutMarkers(): void
    {
        // The cheap-skip path runs zero regex passes; verify a heavily
        // bracket-laden input that would trip a naive regex still passes.
        $html = '<p>Brackets [foo] [bar] here, but no REX vars.</p>';
        $result = EditorContentScanner::scan($html);
        self::assertSame($html, $result);
    }
}
