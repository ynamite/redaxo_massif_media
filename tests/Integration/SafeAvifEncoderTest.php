<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Integration;

use Imagick;
use Intervention\Image\ImageManager;
use League\Glide\Api\Encoder as GlideEncoder;
use PHPUnit\Framework\TestCase;
use Ynamite\Media\Glide\SafeAvifEncoder;

/**
 * Verifies SafeAvifEncoder against a real Imagick build via a fixture image.
 *
 * The bug we're guarding against (intervention/image v3's AvifEncoder
 * producing 0-byte output on Plesk-shipped Imagick) doesn't reproduce on
 * every Imagick build — most builds tolerate intervention/image's
 * setCompression(COMPRESSION_ZIP) + getImagesBlob pattern fine. But our
 * minimal pattern (setImageFormat → setImageCompressionQuality →
 * getImageBlob) is the *intersection* of what works across both build
 * categories. So this test verifies the override path produces a
 * well-formed AVIF on whatever Imagick the test runner ships, which is
 * sufficient for catching regressions if the override logic itself
 * breaks (e.g. someone swaps `getImageBlob` back to `getImagesBlob`).
 *
 * Tests are skipped when Imagick is missing or lacks AVIF — there's no
 * point asserting AVIF behaviour on a host that can't encode AVIF.
 */
final class SafeAvifEncoderTest extends TestCase
{
    private static function imagickHasAvif(): bool
    {
        if (!extension_loaded('imagick')) {
            return false;
        }
        return in_array('AVIF', (new Imagick())->queryFormats(), true);
    }

    protected function setUp(): void
    {
        if (!self::imagickHasAvif()) {
            self::markTestSkipped('Imagick with AVIF support required.');
        }
    }

    public function testEncodesAvifProducingNonEmptyBlob(): void
    {
        $encoder = new SafeAvifEncoder();
        $encoder->setParams(['fm' => 'avif', 'q' => 50]);

        $manager = ImageManager::imagick();
        $image = $manager->read(file_get_contents(__DIR__ . '/../_fixtures/landscape-800x600.jpg'));

        $encoded = $encoder->run($image);

        self::assertSame('image/avif', $encoded->mediaType());
        $blob = (string) $encoded;

        // The whole point of the override: NOT 0 bytes. The vincafilm.ch
        // bug we're guarding against produces a literally empty blob from
        // intervention/image v3's AvifEncoder. Some Imagick builds produce
        // small but valid AVIF (Mac ImageMagick 7.1.x with libheif emits
        // ~325 byte AVIF for our fixture regardless of quality — the build
        // appears to under-report the output size, but the ftypavif check
        // below confirms it's still a well-formed AVIF container). We
        // therefore assert non-empty rather than a meaningful lower bound.
        self::assertGreaterThan(0, strlen($blob), 'AVIF blob is empty (the vincafilm bug).');

        // ftyp box at byte offset 4 with major brand `avif` is the AVIF
        // container fingerprint per ISO/IEC 23008-12. Confirms Imagick
        // emitted an actual AVIF file rather than e.g. a JPEG with the
        // wrong MIME label or a stray header alone.
        self::assertSame('ftypavif', substr($blob, 4, 8), 'Encoded blob is not a well-formed AVIF.');
    }

    public function testFallsThroughToParentForNonAvifFormat(): void
    {
        $encoder = new SafeAvifEncoder();
        $encoder->setParams(['fm' => 'webp', 'q' => 75]);

        $manager = ImageManager::imagick();
        $image = $manager->read(file_get_contents(__DIR__ . '/../_fixtures/landscape-800x600.jpg'));

        $encoded = $encoder->run($image);

        // Fell through to parent ::run() — which produces WebP via
        // intervention/image's specialized WebpEncoder, no override needed.
        self::assertSame('image/webp', $encoded->mediaType());
        self::assertGreaterThan(100, strlen((string) $encoded));
    }

    public function testIsAGlideEncoder(): void
    {
        // Glide's Api::setEncoder() type-hints the parent Encoder class; this
        // belt-and-braces check guards against a future refactor that
        // accidentally drops the inheritance and breaks the wire-up in
        // Server::create() at runtime.
        self::assertInstanceOf(GlideEncoder::class, new SafeAvifEncoder());
    }
}
