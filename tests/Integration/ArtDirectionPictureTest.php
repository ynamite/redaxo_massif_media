<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Integration;

use PHPUnit\Framework\TestCase;
use rex_config;
use rex_path;
use Ynamite\Media\Config;
use Ynamite\Media\Image;

/**
 * End-to-end art-direction render: stage fixture sources under a tmp media
 * dir, configure default formats / sign key, render via the public facade,
 * assert HTML shape — variant `<source media="…">` blocks before the format-
 * keyed defaults, fallback `<img>` from the default variant.
 */
final class ArtDirectionPictureTest extends TestCase
{
    private string $tmpBase;
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->tmpBase = sys_get_temp_dir() . '/massif_art_int_' . uniqid('', true);
        $this->fixturesDir = __DIR__ . '/../_fixtures';
        rex_path::_setBase($this->tmpBase);
        @mkdir($this->tmpBase . '/media', 0777, true);
        copy($this->fixturesDir . '/landscape-800x600.jpg', $this->tmpBase . '/media/landscape-800x600.jpg');
        copy($this->fixturesDir . '/portrait-600x800.jpg', $this->tmpBase . '/media/portrait-600x800.jpg');
        copy($this->fixturesDir . '/square-400x400.png', $this->tmpBase . '/media/square-400x400.png');
        rex_config::set(Config::ADDON, Config::KEY_SIGN_KEY, 'integration-test-key');
        rex_config::set(Config::ADDON, Config::KEY_FORMATS, 'webp,jpg');
        // LQIP / dominant color disabled — we don't want to invoke Imagick
        // for these structural assertions.
        rex_config::set(Config::ADDON, Config::KEY_LQIP_ENABLED, 0);
        rex_config::set(Config::ADDON, Config::KEY_COLOR_ENABLED, 0);
    }

    protected function tearDown(): void
    {
        rex_config::_reset();
        if (is_dir($this->tmpBase)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmpBase, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($items as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->tmpBase);
        }
    }

    public function testArtDirectionEmitsMediaKeyedSourcesBeforeDefaults(): void
    {
        $html = Image::for('landscape-800x600.jpg')
            ->alt('Hero')
            ->art([
                ['media' => '(max-width: 600px)', 'src' => 'portrait-600x800.jpg', 'ratio' => 0.75],
            ])
            ->render();

        self::assertStringStartsWith('<picture>', $html);
        self::assertStringEndsWith('</picture>', $html);

        // Variant <source> for portrait MUST appear before the default-format
        // <source> for landscape — browser cascade picks the first matching tag.
        $variantSourcePos = strpos($html, 'media="(max-width: 600px)"');
        $defaultSourcePos = strpos($html, 'type="image/webp" srcset=');
        // The default <source> for webp has no `media=` attribute, so we look
        // for the unfiltered variant. Just confirm BOTH exist and the variant
        // is earlier.
        self::assertNotFalse($variantSourcePos);
        self::assertNotFalse($defaultSourcePos);
        self::assertLessThan($defaultSourcePos, $variantSourcePos);

        // Variant URL points at the portrait crop, not the landscape default.
        self::assertMatchesRegularExpression(
            '@<source media="\(max-width: 600px\)"[^>]+srcset="[^"]*portrait-600x800\.jpg/webp[^"]*"@',
            $html,
        );

        // Fallback <img> still uses the default landscape source.
        self::assertMatchesRegularExpression(
            '@<img[^>]+src="[^"]*landscape-800x600\.jpg[^"]*\.jpg[^"]*"@',
            $html,
        );
    }

    public function testMultipleArtVariantsKeepCascadeOrder(): void
    {
        $html = Image::for('landscape-800x600.jpg')
            ->alt('Hero')
            ->art([
                ['media' => '(max-width: 480px)', 'src' => 'portrait-600x800.jpg', 'ratio' => 0.75],
                ['media' => '(max-width: 1024px)', 'src' => 'square-400x400.png', 'ratio' => 1.0],
            ])
            ->render();

        $smallPos = strpos($html, 'media="(max-width: 480px)"');
        $mediumPos = strpos($html, 'media="(max-width: 1024px)"');
        self::assertNotFalse($smallPos);
        self::assertNotFalse($mediumPos);
        self::assertLessThan($mediumPos, $smallPos, 'Variants emit in declaration order');
    }

    public function testInvalidVariantSrcSkipsThatVariantWithoutBreakingPicture(): void
    {
        // A bad src for one variant should drop only that variant — the other
        // variants and the default render proceed.
        $html = Image::for('landscape-800x600.jpg')
            ->alt('Hero')
            ->art([
                ['media' => '(max-width: 600px)', 'src' => 'no-such-file.jpg', 'ratio' => 1],
                ['media' => '(min-width: 601px)', 'src' => 'portrait-600x800.jpg', 'ratio' => 0.75],
            ])
            ->render();

        self::assertStringNotContainsString('no-such-file.jpg', $html);
        self::assertStringContainsString('media="(min-width: 601px)"', $html);
        self::assertStringContainsString('portrait-600x800.jpg', $html);
        // Default still renders.
        self::assertStringContainsString('landscape-800x600.jpg', $html);
    }

    public function testEmptyArtListRendersIdenticallyToNoArtCall(): void
    {
        $with = Image::for('landscape-800x600.jpg')->alt('Hero')->art([])->render();
        $without = Image::for('landscape-800x600.jpg')->alt('Hero')->render();

        self::assertSame($without, $with);
    }

    public function testArtVariantFocalAppearsInGlideUrlPath(): void
    {
        // Variant focal differs from default (which is null) → cover-X-Y token
        // should appear in the variant's srcset URLs.
        $html = Image::for('landscape-800x600.jpg')
            ->alt('Hero')
            ->art([
                [
                    'media' => '(max-width: 600px)',
                    'src' => 'portrait-600x800.jpg',
                    'ratio' => 1,
                    'focal' => '50% 30%',
                ],
            ])
            ->render();

        // Variant <source srcset> contains a 1:1 crop with focal tokens.
        self::assertMatchesRegularExpression(
            '@<source media="\(max-width: 600px\)"[^>]+srcset="[^"]*portrait-600x800\.jpg/webp-\d+-\d+-cover-50-30-\d+\.webp@',
            $html,
        );
    }
}
