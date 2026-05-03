<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\View;

use PHPUnit\Framework\TestCase;
use rex_config;
use rex_dir;
use rex_path;
use Ynamite\Media\Config;
use Ynamite\Media\Enum\FetchPriority;
use Ynamite\Media\Enum\Fit;
use Ynamite\Media\Enum\Loading;
use Ynamite\Media\Pipeline\DominantColor;
use Ynamite\Media\Pipeline\Placeholder;
use Ynamite\Media\Pipeline\ResolvedImage;
use Ynamite\Media\Pipeline\SrcsetBuilder;
use Ynamite\Media\Pipeline\UrlBuilder;
use Ynamite\Media\View\PictureRenderer;

final class PictureRendererTest extends TestCase
{
    private string $tmpBase;

    protected function setUp(): void
    {
        $this->tmpBase = sys_get_temp_dir() . '/massif_media_picturerenderer_' . uniqid('', true);
        rex_path::_setBase($this->tmpBase);
        // Default: LQIP off so Placeholder::generate() short-circuits without
        // touching Glide / FS. Individual tests opt in with seedLqipCache().
        rex_config::set(Config::ADDON, Config::KEY_LQIP_ENABLED, 0);
        rex_config::set(Config::ADDON, Config::KEY_SIGN_KEY, 'unit-test-key');
    }

    protected function tearDown(): void
    {
        rex_config::_reset();
        if (is_dir($this->tmpBase)) {
            rex_dir::delete($this->tmpBase, true);
        }
    }

    private function renderer(): PictureRenderer
    {
        return new PictureRenderer(
            new SrcsetBuilder(),
            new UrlBuilder(),
            new Placeholder(),
            new DominantColor(),
        );
    }

    private function image(
        int $w = 1600,
        int $h = 900,
        ?string $focal = null,
    ): ResolvedImage {
        return new ResolvedImage(
            sourcePath: 'hero.jpg',
            absolutePath: $this->tmpBase . '/media/hero.jpg',
            intrinsicWidth: $w,
            intrinsicHeight: $h,
            mime: 'image/jpeg',
            sourceFormat: 'jpg',
            focalPoint: $focal,
            mtime: 1_700_000_000,
        );
    }

    /**
     * Pre-seed the LQIP cache for a given image so Placeholder::generate
     * returns the seeded value without invoking Glide.
     */
    private function seedLqipCache(ResolvedImage $image, string $dataUri): void
    {
        rex_config::set(Config::ADDON, Config::KEY_LQIP_ENABLED, 1);
        $hash = hash('xxh64', $image->sourcePath . ':' . $image->mtime . ':v2');
        $cachePath = rex_path::addonAssets(
            Config::ADDON,
            'cache/_lqip/' . substr($hash, 0, 2) . '/' . $hash . '.txt',
        );
        @mkdir(dirname($cachePath), 0777, true);
        file_put_contents($cachePath, $dataUri);
    }

    /**
     * Pre-seed the dominant-color cache so DominantColor::generate returns
     * the seeded value without invoking Imagick.
     */
    private function seedColorCache(ResolvedImage $image, string $hex): void
    {
        rex_config::set(Config::ADDON, Config::KEY_COLOR_ENABLED, 1);
        $hash = hash('xxh64', $image->sourcePath . ':' . $image->mtime . ':v1');
        $cachePath = rex_path::addonAssets(
            Config::ADDON,
            'cache/_color/' . substr($hash, 0, 2) . '/' . $hash . '.txt',
        );
        @mkdir(dirname($cachePath), 0777, true);
        file_put_contents($cachePath, $hex);
    }

    // --- Smoke / structure ---------------------------------------------------

    public function testEmitsPictureWithSourcesAndImg(): void
    {
        $html = $this->renderer()->render($this->image(), alt: 'Hero');

        self::assertStringStartsWith('<picture>', $html);
        self::assertStringEndsWith('</picture>', $html);
        // Default formats: avif, webp, jpg → two <source> (avif, webp) + <img> (jpg fallback)
        self::assertSame(2, substr_count($html, '<source '));
        self::assertStringContainsString('type="image/avif"', $html);
        self::assertStringContainsString('type="image/webp"', $html);
        self::assertStringNotContainsString('type="image/jpg"', $html);
        self::assertStringNotContainsString('type="image/jpeg"', $html);
        self::assertStringContainsString('<img', $html);
        self::assertStringContainsString('alt="Hero"', $html);
    }

    public function testFallbackFormatIsTheLastInTheList(): void
    {
        $html = $this->renderer()->render(
            $this->image(),
            alt: 'x',
            formats: ['webp', 'jpg', 'avif'],
        );

        // Last format = avif → no <source> for avif, all srcset URLs for the
        // <img> fallback should be .avif.
        self::assertSame(2, substr_count($html, '<source '));
        self::assertStringContainsString('type="image/webp"', $html);
        self::assertStringContainsString('type="image/jpeg"', $html);
        self::assertStringNotContainsString('type="image/avif"', $html);
        self::assertMatchesRegularExpression('/<img[^>]+src="[^"]+\.avif/', $html);
    }

    // --- Intrinsic width / height attributes ---------------------------------

    public function testWidthOnlyDerivesHeightFromIntrinsicRatio(): void
    {
        // 1600x900 → 16:9 → width 800 → height 450
        $html = $this->renderer()->render($this->image(), width: 800, alt: 'x');

        self::assertMatchesRegularExpression('/<img[^>]+ width="800"/', $html);
        self::assertMatchesRegularExpression('/<img[^>]+ height="450"/', $html);
    }

    public function testWidthAndHeightAreEmittedAsGiven(): void
    {
        $html = $this->renderer()->render($this->image(), width: 400, height: 300, alt: 'x');

        self::assertMatchesRegularExpression('/<img[^>]+ width="400"/', $html);
        self::assertMatchesRegularExpression('/<img[^>]+ height="300"/', $html);
    }

    public function testRatioOnlyDerivesHeightFromWidth(): void
    {
        // No width → uses intrinsic 1600 → 1600 / 2.0 = 800
        $html = $this->renderer()->render($this->image(), ratio: 2.0, alt: 'x');

        self::assertMatchesRegularExpression('/<img[^>]+ width="1600"/', $html);
        self::assertMatchesRegularExpression('/<img[^>]+ height="800"/', $html);
    }

    public function testExplicitHeightWinsOverRatioForIntrinsicAttrs(): void
    {
        // computeIntrinsicAttrs prefers explicit height over ratio-derived height.
        $html = $this->renderer()->render($this->image(), width: 400, height: 300, ratio: 4.0, alt: 'x');

        self::assertMatchesRegularExpression('/<img[^>]+ width="400"/', $html);
        self::assertMatchesRegularExpression('/<img[^>]+ height="300"/', $html);
    }

    public function testIntrinsicHeightFallbackWhenNothingProvided(): void
    {
        $html = $this->renderer()->render($this->image(1600, 900), alt: 'x');

        self::assertMatchesRegularExpression('/<img[^>]+ width="1600"/', $html);
        self::assertMatchesRegularExpression('/<img[^>]+ height="900"/', $html);
    }

    // --- Cropping behaviour --------------------------------------------------

    public function testFitNoneSkipsCrop(): void
    {
        $html = $this->renderer()->render(
            $this->image(),
            width: 400,
            height: 400,
            alt: 'x',
            fit: Fit::NONE,
        );

        // No fit token in the URL — srcset URLs end in `<width>w` after a
        // {fmt}-{w}-{q}.{fmt} cache path with no -cover-X-Y / -contain segment.
        self::assertDoesNotMatchRegularExpression('/cover-\d+-\d+|contain|stretch/', $html);
    }

    public function testFitCoverWithFocalPointEmitsObjectPositionAndCropToken(): void
    {
        $html = $this->renderer()->render(
            $this->image(focal: '25% 75%'),
            width: 400,
            height: 400,
            alt: 'x',
        );

        // object-position passes the raw focal string; URLs carry rounded ints
        self::assertStringContainsString('object-position:25% 75%', $html);
        self::assertMatchesRegularExpression('/cover-25-75/', $html);
    }

    public function testFitCoverWithoutFocalPointDefaultsTo50_50(): void
    {
        // 1:1 box on a 16:9 source → needsCrop true, no focal → cover-50-50
        $html = $this->renderer()->render(
            $this->image(),
            width: 400,
            height: 400,
            alt: 'x',
        );

        self::assertMatchesRegularExpression('/cover-50-50/', $html);
        self::assertStringNotContainsString('object-position', $html);
    }

    public function testIntrinsicEqualToTargetRatioSkipsCropFastPath(): void
    {
        // 1600x900 = 16:9. Target ratio 16:9. Within RATIO_EQUAL_EPSILON → no crop.
        $html = $this->renderer()->render(
            $this->image(1600, 900),
            ratio: 16 / 9,
            alt: 'x',
        );

        self::assertDoesNotMatchRegularExpression('/cover-\d+-\d+/', $html);
    }

    public function testFitContainPassesThrough(): void
    {
        $html = $this->renderer()->render(
            $this->image(),
            width: 400,
            height: 400,
            alt: 'x',
            fit: Fit::CONTAIN,
        );

        self::assertMatchesRegularExpression('/-contain-/', $html);
    }

    public function testFitStretchPassesThrough(): void
    {
        $html = $this->renderer()->render(
            $this->image(),
            width: 400,
            height: 400,
            alt: 'x',
            fit: Fit::STRETCH,
        );

        self::assertMatchesRegularExpression('/-stretch-/', $html);
    }

    public function testFitStretchSkipsEffectiveMaxWidthClamp(): void
    {
        // For COVER on a 1600x900 source with 1:1 ratio, srcset is capped at
        // intrinsicHeight * ratio = 900. STRETCH should NOT cap and should
        // include widths above 900.
        $cover = $this->renderer()->render(
            $this->image(1600, 900),
            width: 400,
            height: 400,
            alt: 'x',
            fit: Fit::COVER,
            widthsOverride: [400, 800, 1200, 1600],
        );
        $stretch = $this->renderer()->render(
            $this->image(1600, 900),
            width: 400,
            height: 400,
            alt: 'x',
            fit: Fit::STRETCH,
            widthsOverride: [400, 800, 1200, 1600],
        );

        self::assertStringNotContainsString('1200w', $cover);
        self::assertStringNotContainsString('1600w', $cover);
        self::assertStringContainsString('1200w', $stretch);
        self::assertStringContainsString('1600w', $stretch);
    }

    // --- Empty pool ----------------------------------------------------------

    public function testEmptyWidthPoolReturnsEmptyString(): void
    {
        // SrcsetBuilder always backstops the pool with the cap (intrinsic width
        // or effectiveMaxWidth). The only way to actually produce an empty
        // pool is intrinsicWidth=0 — useful when MetadataReader fails to read
        // dimensions and the renderer should bail rather than emit broken HTML.
        $html = $this->renderer()->render(
            new ResolvedImage(
                sourcePath: 'broken.jpg',
                absolutePath: '/tmp/broken.jpg',
                intrinsicWidth: 0,
                intrinsicHeight: 0,
                mime: 'image/jpeg',
                sourceFormat: 'jpg',
            ),
            alt: 'x',
        );

        self::assertSame('', $html);
    }

    // --- Alt / aria-hidden ---------------------------------------------------

    public function testNullAltAddsAriaHidden(): void
    {
        $html = $this->renderer()->render($this->image());

        self::assertStringContainsString('aria-hidden="true"', $html);
        self::assertStringContainsString('alt=""', $html);
    }

    public function testEmptyAltAddsAriaHidden(): void
    {
        $html = $this->renderer()->render($this->image(), alt: '');

        self::assertStringContainsString('aria-hidden="true"', $html);
    }

    public function testNonEmptyAltOmitsAriaHidden(): void
    {
        $html = $this->renderer()->render($this->image(), alt: 'Hero');

        self::assertStringNotContainsString('aria-hidden', $html);
    }

    // --- Style attribute (LQIP + focal point) --------------------------------

    public function testNoStyleAttrWithoutLqipOrFocal(): void
    {
        $html = $this->renderer()->render($this->image(), alt: 'x');

        self::assertDoesNotMatchRegularExpression('/<img[^>]+ style="/', $html);
    }

    public function testLqipEnabledAddsBackgroundImageStyle(): void
    {
        $image = $this->image();
        $this->seedLqipCache($image, 'data:image/webp;base64,UklGR...');

        $html = $this->renderer()->render($image, alt: 'x');

        // Style attr is htmlspecialchars'd through ENT_QUOTES — the single
        // quotes around the data-URI become &#039;.
        self::assertStringContainsString('background-size:cover', $html);
        self::assertStringContainsString(
            "background-image:url(&#039;data:image/webp;base64,UklGR...&#039;)",
            $html,
        );
    }

    public function testLqipPlusFocalCombinedInOneStyleAttr(): void
    {
        $image = $this->image(focal: '25% 75%');
        $this->seedLqipCache($image, 'data:image/webp;base64,QQ==');

        $html = $this->renderer()->render($image, width: 400, height: 400, alt: 'x');

        self::assertMatchesRegularExpression(
            '/<img[^>]+ style="background-size:cover;background-image:url\(&#039;[^&]+&#039;\);object-position:25% 75%"/',
            $html,
        );
    }

    public function testFocalOnlyAddsObjectPositionStyle(): void
    {
        $html = $this->renderer()->render(
            $this->image(focal: '50% 50%'),
            width: 400,
            height: 400,
            alt: 'x',
        );

        self::assertStringContainsString('style="object-position:50% 50%"', $html);
    }

    public function testDominantColorPrependsBackgroundColor(): void
    {
        $image = $this->image();
        $this->seedColorCache($image, '#3a4f6b');

        $html = $this->renderer()->render($image, alt: 'x');

        // Background color first so it paints immediately; standalone (no LQIP, no focal).
        self::assertStringContainsString('style="background-color:#3a4f6b"', $html);
    }

    public function testDominantColorPlusLqipPaintColorThenLqipImage(): void
    {
        $image = $this->image();
        $this->seedColorCache($image, '#112233');
        $this->seedLqipCache($image, 'data:image/webp;base64,QQ==');

        $html = $this->renderer()->render($image, alt: 'x');

        // Order: background-color → background-size → background-image. Browser
        // paints color first, LQIP overlays it.
        self::assertMatchesRegularExpression(
            '/style="background-color:#112233;background-size:cover;background-image:/',
            $html,
        );
    }

    public function testDominantColorPlusFocalCombinedInOneStyle(): void
    {
        $image = $this->image(focal: '25% 75%');
        $this->seedColorCache($image, '#aabbcc');

        $html = $this->renderer()->render($image, width: 400, height: 400, alt: 'x');

        self::assertStringContainsString(
            'style="background-color:#aabbcc;object-position:25% 75%"',
            $html,
        );
    }

    public function testColorDisabledOmitsBackgroundColor(): void
    {
        // Default state: KEY_COLOR_ENABLED = 0 (no seeding).
        $html = $this->renderer()->render($this->image(), alt: 'x');

        self::assertStringNotContainsString('background-color', $html);
    }

    // --- Misc attributes -----------------------------------------------------

    public function testFetchPriorityHighIsEmitted(): void
    {
        $html = $this->renderer()->render(
            $this->image(),
            alt: 'x',
            fetchPriority: FetchPriority::HIGH,
        );

        self::assertStringContainsString('fetchpriority="high"', $html);
    }

    public function testFetchPriorityAutoIsOmitted(): void
    {
        $html = $this->renderer()->render(
            $this->image(),
            alt: 'x',
            fetchPriority: FetchPriority::AUTO,
        );

        self::assertStringNotContainsString('fetchpriority', $html);
    }

    public function testLoadingEagerEmitted(): void
    {
        $html = $this->renderer()->render(
            $this->image(),
            alt: 'x',
            loading: Loading::EAGER,
        );

        self::assertStringContainsString('loading="eager"', $html);
    }

    public function testClassAttributeAppearsWhenSet(): void
    {
        $html = $this->renderer()->render($this->image(), alt: 'x', class: 'hero u-cover');

        self::assertStringContainsString('class="hero u-cover"', $html);
    }

    public function testCustomSizesOverridesDefault(): void
    {
        $html = $this->renderer()->render($this->image(), alt: 'x', sizes: '100vw');

        // sizes appears on every <source> and on the <img>
        self::assertGreaterThanOrEqual(3, substr_count($html, 'sizes="100vw"'));
    }

    public function testCustomQualityOverrideAppearsInUrl(): void
    {
        $html = $this->renderer()->render(
            $this->image(),
            alt: 'x',
            qualityOverride: ['avif' => 12],
            widthsOverride: [400],
        );

        // avif source should carry q=12 in its cache path; default jpg fallback
        // (q=80) should not.
        self::assertMatchesRegularExpression('/avif-400-12\.avif/', $html);
        self::assertMatchesRegularExpression('/jpg-400-80\.jpg/', $html);
    }

    public function testWidthsOverrideReplacesDefaultPool(): void
    {
        $html = $this->renderer()->render(
            $this->image(),
            alt: 'x',
            widthsOverride: [320, 640],
        );

        self::assertStringContainsString('320w', $html);
        self::assertStringContainsString('640w', $html);
        self::assertStringNotContainsString('1080w', $html);
        self::assertStringNotContainsString('1920w', $html);
    }

    public function testFiltersBlobAppendedToUrls(): void
    {
        $html = $this->renderer()->render(
            $this->image(),
            alt: 'x',
            filterParams: ['blur' => 5],
        );

        // URLs are emitted in HTML attributes — `&` becomes `&amp;`.
        self::assertStringContainsString('&amp;f=', $html);
        // Cache path carries the 8-char filter hash before the extension.
        self::assertMatchesRegularExpression('/-f[a-f0-9]{8}\.(?:avif|webp|jpg)/', $html);
    }

    public function testHtmlEscapingInAlt(): void
    {
        $html = $this->renderer()->render($this->image(), alt: 'A & B "C"');

        self::assertStringContainsString('alt="A &amp; B &quot;C&quot;"', $html);
    }
}
