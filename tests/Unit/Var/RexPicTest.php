<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Var;

use PHPUnit\Framework\TestCase;
use Ynamite\Media\Var\RexPic;

final class RexPicTest extends TestCase
{
    private function buildOutput(array $args): string|false
    {
        $rex = new RexPic();
        $rex->_setArgs($args);
        return $rex->_callGetOutput();
    }

    public function testGetOutputReturnsFalseWithoutSrc(): void
    {
        self::assertFalse($this->buildOutput([]));
    }

    public function testGetOutputEmitsImagePictureCall(): void
    {
        $code = $this->buildOutput(['src' => 'hero.jpg', 'alt' => 'A view']);

        self::assertIsString($code);
        self::assertStringStartsWith('\\Ynamite\\Media\\Image::picture(', $code);
        self::assertStringContainsString("src: 'hero.jpg'", $code);
        self::assertStringContainsString("alt: 'A view'", $code);
    }

    public function testGetOutputEmitsRatioFromColonSyntax(): void
    {
        $code = $this->buildOutput(['src' => 'hero.jpg', 'ratio' => '16:9']);

        // 16/9 = 1.7777777...
        self::assertIsString($code);
        self::assertMatchesRegularExpression('/ratio: 1\.7\d+/', $code);
    }

    public function testGetOutputEmitsRatioFromSlashSyntax(): void
    {
        $code = $this->buildOutput(['src' => 'hero.jpg', 'ratio' => '4/3']);

        self::assertIsString($code);
        self::assertMatchesRegularExpression('/ratio: 1\.3\d+/', $code);
    }

    public function testGetOutputEmitsPreloadAsBoolean(): void
    {
        $codeTrue = $this->buildOutput(['src' => 'hero.jpg', 'preload' => 'true']);
        $codeFalse = $this->buildOutput(['src' => 'hero.jpg', 'preload' => 'false']);
        $codeMissing = $this->buildOutput(['src' => 'hero.jpg']);

        self::assertIsString($codeTrue);
        self::assertIsString($codeFalse);
        self::assertIsString($codeMissing);
        self::assertStringContainsString('preload: true', $codeTrue);
        self::assertStringContainsString('preload: false', $codeFalse);
        self::assertStringNotContainsString('preload:', $codeMissing);
    }

    public function testGetOutputEmitsFetchpriorityWithCamelCase(): void
    {
        $code = $this->buildOutput(['src' => 'hero.jpg', 'fetchpriority' => 'high']);

        self::assertIsString($code);
        self::assertStringContainsString("fetchPriority: 'high'", $code);
        self::assertStringNotContainsString('fetchpriority:', $code);
    }

    public function testGetOutputEmitsWidthAsInt(): void
    {
        $code = $this->buildOutput(['src' => 'hero.jpg', 'width' => '800']);

        self::assertIsString($code);
        self::assertStringContainsString('width: 800', $code);
    }

    public function testGetOutputEmitsFiltersArrayForBrightness(): void
    {
        $code = $this->buildOutput(['src' => 'hero.jpg', 'brightness' => '10']);

        self::assertIsString($code);
        self::assertStringContainsString("filters: ['brightness' => 10]", $code);
    }

    public function testGetOutputEmitsFiltersArrayForMultipleAttributes(): void
    {
        $code = $this->buildOutput([
            'src' => 'hero.jpg',
            'brightness' => '10',
            'sharpen' => '20',
            'filter' => 'sepia',
        ]);

        self::assertIsString($code);
        self::assertStringContainsString('filters: [', $code);
        self::assertStringContainsString("'brightness' => 10", $code);
        self::assertStringContainsString("'sharpen' => 20", $code);
        self::assertStringContainsString("'filter' => 'sepia'", $code);
    }

    public function testGetOutputOmitsFiltersWhenNonePresent(): void
    {
        $code = $this->buildOutput(['src' => 'hero.jpg']);

        self::assertIsString($code);
        self::assertStringNotContainsString('filters:', $code);
    }

    public function testAsUrlEmitsImageUrlCall(): void
    {
        $code = $this->buildOutput([
            'src' => 'hero.jpg',
            'width' => '1280',
            'as' => 'url',
        ]);

        self::assertIsString($code);
        self::assertStringStartsWith('\\Ynamite\\Media\\Image::url(', $code);
        self::assertStringContainsString("src: 'hero.jpg'", $code);
        self::assertStringContainsString('width: 1280', $code);
        // Must NOT emit picture()-only args.
        self::assertStringNotContainsString('Image::picture', $code);
    }

    public function testAsUrlSkipsRenderOnlyAttributes(): void
    {
        // alt / sizes / loading / decoding / fetchpriority / preload / class
        // are <img>-element attributes — irrelevant for a single URL.
        $code = $this->buildOutput([
            'src' => 'hero.jpg',
            'width' => '1280',
            'as' => 'url',
            'alt' => 'should-be-ignored',
            'sizes' => '100vw',
            'loading' => 'eager',
            'decoding' => 'sync',
            'fetchpriority' => 'high',
            'preload' => 'true',
            'class' => 'hero',
        ]);

        self::assertIsString($code);
        self::assertStringNotContainsString('alt:', $code);
        self::assertStringNotContainsString('sizes:', $code);
        self::assertStringNotContainsString('loading:', $code);
        self::assertStringNotContainsString('decoding:', $code);
        self::assertStringNotContainsString('fetchPriority:', $code);
        self::assertStringNotContainsString('preload:', $code);
        self::assertStringNotContainsString('class:', $code);
    }

    public function testAsUrlPropagatesFitAndFocal(): void
    {
        $code = $this->buildOutput([
            'src' => 'hero.jpg',
            'width' => '1280',
            'as' => 'url',
            'fit' => 'cover',
            'focal' => '25% 75%',
        ]);

        self::assertIsString($code);
        self::assertStringContainsString("fit: 'cover'", $code);
        self::assertStringContainsString("focal: '25% 75%'", $code);
    }

    public function testAsUrlPropagatesFormatAndQuality(): void
    {
        $code = $this->buildOutput([
            'src' => 'hero.jpg',
            'width' => '1280',
            'as' => 'url',
            'format' => 'jpg',
            'quality' => '82',
        ]);

        self::assertIsString($code);
        self::assertStringContainsString("format: 'jpg'", $code);
        self::assertStringContainsString('quality: 82', $code);
    }

    public function testAsUrlPropagatesRatio(): void
    {
        $code = $this->buildOutput([
            'src' => 'hero.jpg',
            'width' => '1280',
            'as' => 'url',
            'ratio' => '16:9',
        ]);

        self::assertIsString($code);
        self::assertMatchesRegularExpression('/ratio: 1\.7\d+/', $code);
    }

    public function testAsUrlPropagatesFilters(): void
    {
        $code = $this->buildOutput([
            'src' => 'hero.jpg',
            'width' => '1280',
            'as' => 'url',
            'brightness' => '10',
            'sharpen' => '20',
        ]);

        self::assertIsString($code);
        self::assertStringContainsString("filters: [", $code);
        self::assertStringContainsString("'brightness' => 10", $code);
        self::assertStringContainsString("'sharpen' => 20", $code);
    }

    public function testAsAttributeOtherThanUrlFallsBackToPicture(): void
    {
        // Future-proofing: only the literal value 'url' triggers the URL
        // branch. Anything else (`as="image"`, missing) → standard picture.
        $codeImage = $this->buildOutput(['src' => 'hero.jpg', 'as' => 'image']);
        $codeMissing = $this->buildOutput(['src' => 'hero.jpg']);

        self::assertIsString($codeImage);
        self::assertIsString($codeMissing);
        self::assertStringStartsWith('\\Ynamite\\Media\\Image::picture(', $codeImage);
        self::assertStringStartsWith('\\Ynamite\\Media\\Image::picture(', $codeMissing);
    }

    public function testGetOutputPassesWatermarkAttributes(): void
    {
        $code = $this->buildOutput([
            'src' => 'hero.jpg',
            'mark' => 'logo.png',
            'markpos' => 'bottom-right',
            'markalpha' => '70',
        ]);

        self::assertIsString($code);
        self::assertStringContainsString("'mark' => 'logo.png'", $code);
        self::assertStringContainsString("'markpos' => 'bottom-right'", $code);
        self::assertStringContainsString("'markalpha' => 70", $code);
    }

    public function testArtAttributeAcceptsObjectShape(): void
    {
        // Preferred shape for slice content: REDAXO's rex_var tokenizer regex
        // forbids unescaped square brackets, so list-shape JSON breaks the
        // entire REX_PIC tag from being parsed at all.
        $code = $this->buildOutput([
            'src' => 'hero.jpg',
            'art' => '{"sm":{"media":"(max-width: 600px)","src":"hero-portrait.jpg","ratio":1,"focal":"50% 30%"},"md":{"media":"(max-width: 1024px)","src":"hero-tablet.jpg","ratio":"4/3"}}',
        ]);

        self::assertIsString($code);
        self::assertStringContainsString('art: json_decode(', $code);
        self::assertStringContainsString('(max-width: 600px)', $code);
        self::assertStringContainsString('hero-portrait.jpg', $code);
        self::assertStringContainsString('(max-width: 1024px)', $code);
        self::assertStringContainsString('hero-tablet.jpg', $code);
    }

    public function testArtAttributeObjectShapePreservesKeyOrder(): void
    {
        // Order matters for art direction (more specific media queries first);
        // PHP's json_decode preserves source order for object keys.
        $code = $this->buildOutput([
            'src' => 'hero.jpg',
            'art' => '{"second":{"media":"(max-width: 1024px)","src":"b.jpg"},"first":{"media":"(max-width: 600px)","src":"a.jpg"}}',
        ]);

        self::assertIsString($code);
        $posB = strpos($code, '1024px');
        $posA = strpos($code, '600px');
        self::assertNotFalse($posB);
        self::assertNotFalse($posA);
        self::assertLessThan($posA, $posB, 'first declared variant must precede later ones');
    }

    public function testArtAttributeStillAcceptsListShape(): void
    {
        // List shape can't actually reach RexPic via rex_var::parse (regex
        // bars unescaped brackets), but is still valid for direct PHP use of
        // Image::picture(art: …). Keep it accepted here so the JSON contract
        // doesn't diverge between editor + PHP entry points.
        $code = $this->buildOutput([
            'src' => 'hero.jpg',
            'art' => '[{"media":"(max-width: 600px)","src":"a.jpg"}]',
        ]);

        self::assertIsString($code);
        self::assertStringContainsString('art: json_decode(', $code);
        self::assertStringContainsString('(max-width: 600px)', $code);
    }

    public function testArtAttributeFiltersInvalidEntriesFromObjectShape(): void
    {
        // Per-entry validation runs after the object → list flattening.
        // An entry missing src would normally be dropped, BUT we now inherit
        // the parent picture's src — so missing-src entries are kept iff the
        // parent has one. Use a non-string media to exercise the actual drop
        // path (parent fallback can't rescue a bad media value).
        $code = $this->buildOutput([
            'src' => 'hero.jpg',
            'art' => '{"good":{"media":"(max-width: 600px)","src":"a.jpg"},"bad":{"media":123,"src":"b.jpg"}}',
        ]);

        self::assertIsString($code);
        self::assertStringContainsString('a.jpg', $code);
        self::assertStringNotContainsString('b.jpg', $code);
    }

    public function testArtVariantInheritsParentSrcWhenOmitted(): void
    {
        // Common slice-author case: same image, different crop/focal per
        // breakpoint — variant src can be omitted and defaults to the
        // parent picture's src.
        $code = $this->buildOutput([
            'src' => 'hero.jpg',
            'art' => '{"sm":{"media":"(max-width: 600px)","ratio":1,"focal":"50% 30%"}}',
        ]);

        self::assertIsString($code);
        self::assertStringContainsString('art: json_decode(', $code);
        // Parent src should appear inside the variant JSON (in addition to
        // the top-level `src: 'hero.jpg'` of the picture itself).
        $occurrences = substr_count($code, 'hero.jpg');
        self::assertGreaterThanOrEqual(2, $occurrences, 'parent src must be inlined into variant');
    }

    public function testArtVariantInheritsParentSrcWhenEmpty(): void
    {
        // Empty-string src is treated as "use the default" (friendlier than
        // dropping the whole variant for a typo / placeholder leftover).
        $code = $this->buildOutput([
            'src' => 'hero.jpg',
            'art' => '{"sm":{"media":"(max-width: 600px)","src":"","ratio":1}}',
        ]);

        self::assertIsString($code);
        self::assertStringContainsString('hero.jpg', $code);
        $occurrences = substr_count($code, 'hero.jpg');
        self::assertGreaterThanOrEqual(2, $occurrences);
    }

    public function testArtVariantExplicitSrcOverridesParent(): void
    {
        // Sanity: when a variant declares its own src, parent inheritance
        // does NOT overwrite it.
        $code = $this->buildOutput([
            'src' => 'hero.jpg',
            'art' => '{"sm":{"media":"(max-width: 600px)","src":"hero-mobile.jpg"}}',
        ]);

        self::assertIsString($code);
        self::assertStringContainsString('hero-mobile.jpg', $code);
    }

    public function testArtAttributeAcceptsCommaSeparatedBareVariants(): void
    {
        // Most natural slice-content shape: looks like a list without the
        // outer `[…]` (which REDAXO's tokenizer can't handle anyway). The
        // [] wrap fallback rescues this when the primary parse fails.
        $code = $this->buildOutput([
            'src' => 'hero.jpg',
            'art' => '{"media":"(max-width: 600px)","ratio":1,"focal":"50% 30%"},{"media":"(max-width: 1024px)","ratio":"4/3"}',
        ]);

        self::assertIsString($code);
        self::assertStringContainsString('art: json_decode(', $code);
        self::assertStringContainsString('(max-width: 600px)', $code);
        self::assertStringContainsString('(max-width: 1024px)', $code);
        // Both variants inherit parent src via the auto-inherit path.
        $occurrences = substr_count($code, 'hero.jpg');
        self::assertGreaterThanOrEqual(3, $occurrences, 'parent + 2 variants = 3+ src occurrences');
    }

    public function testArtAttributeAcceptsSingleBareVariant(): void
    {
        // Single bare variant: object whose first value is scalar. Must not
        // be mistaken for a keyed-id map (which would have array values).
        $code = $this->buildOutput([
            'src' => 'hero.jpg',
            'art' => '{"media":"(max-width: 600px)","ratio":1}',
        ]);

        self::assertIsString($code);
        self::assertStringContainsString('art: json_decode(', $code);
        self::assertStringContainsString('(max-width: 600px)', $code);
    }

    public function testArtAttributeMalformedJsonReturnsPictureWithoutArt(): void
    {
        // Truly broken JSON (after both primary and []-wrap attempts) is
        // logged via rex_logger and the picture renders without art direction
        // — never throws or emits half-broken markup.
        $code = $this->buildOutput([
            'src' => 'hero.jpg',
            'art' => '{ this is not json at all',
        ]);

        self::assertIsString($code);
        self::assertStringStartsWith('\\Ynamite\\Media\\Image::picture(', $code);
        self::assertStringNotContainsString('art:', $code);
    }
}
