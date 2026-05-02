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
}
