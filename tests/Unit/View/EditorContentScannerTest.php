<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\View;

use PHPUnit\Framework\TestCase;
use Ynamite\Media\View\EditorContentScanner;

final class EditorContentScannerTest extends TestCase
{
    public function testScanReturnsOriginalWhenNoMarkers(): void
    {
        $html = '<p>Plain content with no markers at all.</p>';
        self::assertSame($html, EditorContentScanner::scan($html));
    }

    public function testScanCheapSkipsCaseInsensitively(): void
    {
        // stripos is case-insensitive, but the regex match is case-sensitive
        // (REX_VARs are uppercase by REDAXO convention). A `rex_pic[…]` lower
        // would slip past the cheap-skip but never match the regex, so we'd
        // just fall through and emit the original substring unchanged.
        $html = '<p>discussion of rex_pic[lowercase]</p>';
        self::assertSame($html, EditorContentScanner::scan($html));
    }

    public function testScanLogsAndPreservesLiteralWhenSrcMissing(): void
    {
        $html = '<p>REX_PIC[alt="Missing src"]</p>';
        $result = EditorContentScanner::scan($html);
        self::assertSame($html, $result);
    }

    public function testScanPreservesLiteralWhenImageResolverFails(): void
    {
        // No mediapool / sign-key bootstrap → Image::picture throws somewhere
        // in the resolve pipeline. Scanner catches and leaves the literal in
        // place, matching the README's "fail open" promise.
        $html = '<p>REX_PIC[src="this-file-does-not-exist.jpg" alt="x" width="800"]</p>';
        $result = EditorContentScanner::scan($html);
        self::assertSame($html, $result);
    }

    public function testScanLogsAndPreservesLiteralWhenVideoSrcMissing(): void
    {
        $html = '<p>REX_VIDEO[poster="x.jpg"]</p>';
        $result = EditorContentScanner::scan($html);
        self::assertSame($html, $result);
    }

    // --- parseAttrs ---

    public function testParseAttrsEmptyString(): void
    {
        self::assertSame([], EditorContentScanner::parseAttrs(''));
    }

    public function testParseAttrsDoubleQuoted(): void
    {
        $result = EditorContentScanner::parseAttrs('src="hero.jpg" alt="A view"');
        self::assertSame(['src' => 'hero.jpg', 'alt' => 'A view'], $result);
    }

    public function testParseAttrsSingleQuoted(): void
    {
        $result = EditorContentScanner::parseAttrs("src='hero.jpg' alt='A view'");
        self::assertSame(['src' => 'hero.jpg', 'alt' => 'A view'], $result);
    }

    public function testParseAttrsBareValue(): void
    {
        $result = EditorContentScanner::parseAttrs('width=800 height=600');
        self::assertSame(['width' => '800', 'height' => '600'], $result);
    }

    public function testParseAttrsMixedQuotes(): void
    {
        $result = EditorContentScanner::parseAttrs('src="hero.jpg" alt=\'A view\' width=800');
        self::assertSame(['src' => 'hero.jpg', 'alt' => 'A view', 'width' => '800'], $result);
    }

    public function testParseAttrsLowercasesKeys(): void
    {
        // REDAXO convention: REX_VAR attributes are lowercase. The cache-build
        // path's `rex_var::toArray` is also case-folding; mirror it so a stray
        // `WIDTH="800"` (paste-from-Word, etc.) round-trips.
        $result = EditorContentScanner::parseAttrs('SRC="hero.jpg" Width="800"');
        self::assertSame(['src' => 'hero.jpg', 'width' => '800'], $result);
    }

    public function testParseAttrsDecodesHtmlEntities(): void
    {
        $result = EditorContentScanner::parseAttrs('alt="Aussicht &uuml;ber das Tal"');
        self::assertSame(['alt' => 'Aussicht über das Tal'], $result);
    }

    public function testParseAttrsDecodesNumericEntities(): void
    {
        $result = EditorContentScanner::parseAttrs('alt="A&#39;view"');
        self::assertSame(['alt' => "A'view"], $result);
    }

    public function testParseAttrsPreservesEmptyDoubleQuoted(): void
    {
        // Editors sometimes save empty attrs (`alt=""`). Allow them through —
        // downstream casting (`stringOrNull`) collapses them back to null.
        $result = EditorContentScanner::parseAttrs('src="hero.jpg" alt=""');
        self::assertArrayHasKey('alt', $result);
        self::assertSame('', $result['alt']);
    }

    public function testParseAttrsHandlesMultilineInput(): void
    {
        // The output filter regex uses /s flag; parseAttrs runs on the body
        // captured by the outer regex, which may legitimately span lines for
        // tags with many attrs.
        $args = "src=\"hero.jpg\"\n  alt=\"A view\"\n  width=\"800\"";
        $result = EditorContentScanner::parseAttrs($args);
        self::assertSame([
            'src' => 'hero.jpg',
            'alt' => 'A view',
            'width' => '800',
        ], $result);
    }

    public function testParseAttrsIgnoresGarbageBetweenPairs(): void
    {
        // Keep parsing forgiving: extra whitespace, stray words, etc. The
        // regex only consumes `key=value` pairs; non-conforming substrings
        // are passed over.
        $result = EditorContentScanner::parseAttrs('src="hero.jpg"  weird-noise  alt="A view"');
        self::assertSame(['src' => 'hero.jpg', 'alt' => 'A view'], $result);
    }
}
