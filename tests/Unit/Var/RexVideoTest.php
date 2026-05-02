<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Var;

use PHPUnit\Framework\TestCase;
use Ynamite\Media\Var\RexVideo;

final class RexVideoTest extends TestCase
{
    private function buildOutput(array $args): string|false
    {
        $rex = new RexVideo();
        $rex->_setArgs($args);
        return $rex->_callGetOutput();
    }

    public function testGetOutputReturnsFalseWithoutSrc(): void
    {
        self::assertFalse($this->buildOutput([]));
    }

    public function testGetOutputEmitsVideoRenderCall(): void
    {
        $code = $this->buildOutput(['src' => 'hero.mp4']);

        self::assertIsString($code);
        self::assertStringStartsWith('\\Ynamite\\Media\\Video::render(', $code);
        self::assertStringContainsString("src: 'hero.mp4'", $code);
    }

    public function testGetOutputEmitsStringPassthroughs(): void
    {
        $code = $this->buildOutput([
            'src' => 'hero.mp4',
            'poster' => 'hero.jpg',
            'alt' => 'Looping background',
            'class' => 'hero-video',
            'preload' => 'auto',
            'loading' => 'eager',
        ]);

        self::assertIsString($code);
        self::assertStringContainsString("poster: 'hero.jpg'", $code);
        self::assertStringContainsString("alt: 'Looping background'", $code);
        self::assertStringContainsString("class: 'hero-video'", $code);
        self::assertStringContainsString("preload: 'auto'", $code);
        self::assertStringContainsString("loading: 'eager'", $code);
    }

    public function testGetOutputEmitsWidthAndHeightAsInts(): void
    {
        $code = $this->buildOutput(['src' => 'hero.mp4', 'width' => '1920', 'height' => '1080']);

        self::assertIsString($code);
        self::assertStringContainsString('width: 1920', $code);
        self::assertStringContainsString('height: 1080', $code);
    }

    public function testGetOutputEmitsBoolAttrsWhenTrue(): void
    {
        $code = $this->buildOutput([
            'src' => 'hero.mp4',
            'autoplay' => 'true',
            'muted' => '1',
            'loop' => 'yes',
        ]);

        self::assertIsString($code);
        self::assertStringContainsString('autoplay: true', $code);
        self::assertStringContainsString('muted: true', $code);
        self::assertStringContainsString('loop: true', $code);
    }

    public function testGetOutputEmitsBoolAttrsWhenFalse(): void
    {
        $code = $this->buildOutput([
            'src' => 'hero.mp4',
            'controls' => 'false',
            'playsinline' => '0',
        ]);

        self::assertIsString($code);
        self::assertStringContainsString('controls: false', $code);
        self::assertStringContainsString('playsinline: false', $code);
    }

    public function testGetOutputOmitsBoolAttrsWhenAbsent(): void
    {
        $code = $this->buildOutput(['src' => 'hero.mp4']);

        self::assertIsString($code);
        // Defaults must come from Video::render(), not from the rex_var, so the
        // emitted PHP must NOT mention the bool keys at all when the editor
        // didn't set them.
        self::assertStringNotContainsString('autoplay:', $code);
        self::assertStringNotContainsString('muted:', $code);
        self::assertStringNotContainsString('loop:', $code);
        self::assertStringNotContainsString('controls:', $code);
        self::assertStringNotContainsString('playsinline:', $code);
    }

    public function testGetOutputCombinesEverything(): void
    {
        $code = $this->buildOutput([
            'src' => 'hero.mp4',
            'poster' => 'hero.jpg',
            'width' => '1920',
            'height' => '1080',
            'alt' => 'Looping background',
            'class' => 'hero-video',
            'preload' => 'auto',
            'autoplay' => 'true',
            'muted' => 'true',
            'loop' => 'true',
            'playsinline' => 'true',
        ]);

        self::assertIsString($code);
        self::assertStringStartsWith('\\Ynamite\\Media\\Video::render(', $code);
        self::assertStringContainsString("src: 'hero.mp4'", $code);
        self::assertStringContainsString("poster: 'hero.jpg'", $code);
        self::assertStringContainsString('width: 1920', $code);
        self::assertStringContainsString('height: 1080', $code);
        self::assertStringContainsString('autoplay: true', $code);
        self::assertStringContainsString('muted: true', $code);
        self::assertStringContainsString('loop: true', $code);
        self::assertStringContainsString('playsinline: true', $code);
    }
}
