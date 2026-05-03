<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\View;

use PHPUnit\Framework\TestCase;
use Ynamite\Media\Enum\Decoding;
use Ynamite\Media\Enum\Loading;
use Ynamite\Media\Pipeline\ResolvedImage;
use Ynamite\Media\View\PassthroughRenderer;

final class PassthroughRendererTest extends TestCase
{
    private function svg(): ResolvedImage
    {
        return new ResolvedImage(
            sourcePath: 'logo.svg',
            absolutePath: '/tmp/logo.svg',
            intrinsicWidth: 200,
            intrinsicHeight: 100,
            mime: 'image/svg+xml',
            sourceFormat: 'svg',
        );
    }

    public function testEmitsImgTagWithIntrinsicDimsByDefault(): void
    {
        $html = (new PassthroughRenderer())->render($this->svg(), alt: 'Hero');

        self::assertStringStartsWith('<img', $html);
        self::assertStringContainsString('src="/media/logo.svg"', $html);
        self::assertStringContainsString('width="200"', $html);
        self::assertStringContainsString('height="100"', $html);
        self::assertStringContainsString('alt="Hero"', $html);
        self::assertStringNotContainsString('aria-hidden', $html);
    }

    public function testWidthOverrideKeepsIntrinsicHeightWhenNoRatio(): void
    {
        $html = (new PassthroughRenderer())->render($this->svg(), width: 400, alt: 'x');

        self::assertStringContainsString('width="400"', $html);
        self::assertStringContainsString('height="100"', $html);
    }

    public function testHeightOverrideTakesPrecedenceOverRatio(): void
    {
        $html = (new PassthroughRenderer())->render($this->svg(), width: 400, height: 250, ratio: 4.0, alt: 'x');

        self::assertStringContainsString('width="400"', $html);
        self::assertStringContainsString('height="250"', $html);
    }

    public function testRatioDerivesHeightFromWidthWhenHeightOmitted(): void
    {
        $html = (new PassthroughRenderer())->render($this->svg(), width: 400, ratio: 2.0, alt: 'x');

        self::assertStringContainsString('width="400"', $html);
        self::assertStringContainsString('height="200"', $html);
    }

    public function testNullAltAddsAriaHidden(): void
    {
        $html = (new PassthroughRenderer())->render($this->svg());

        self::assertStringContainsString('alt=""', $html);
        self::assertStringContainsString('aria-hidden="true"', $html);
    }

    public function testEmptyAltAddsAriaHidden(): void
    {
        $html = (new PassthroughRenderer())->render($this->svg(), alt: '');

        self::assertStringContainsString('aria-hidden="true"', $html);
    }

    public function testNonEmptyAltOmitsAriaHidden(): void
    {
        $html = (new PassthroughRenderer())->render($this->svg(), alt: 'x');

        self::assertStringNotContainsString('aria-hidden', $html);
    }

    public function testClassAttributeAppearsWhenSet(): void
    {
        $html = (new PassthroughRenderer())->render($this->svg(), alt: 'x', class: 'hero-img u-cover');

        self::assertStringContainsString('class="hero-img u-cover"', $html);
    }

    public function testEmptyClassIsOmitted(): void
    {
        $html = (new PassthroughRenderer())->render($this->svg(), alt: 'x', class: '');

        self::assertStringNotContainsString('class=', $html);
    }

    public function testLoadingAndDecodingEnumsAppear(): void
    {
        $html = (new PassthroughRenderer())->render(
            $this->svg(),
            alt: 'x',
            loading: Loading::EAGER,
            decoding: Decoding::SYNC,
        );

        self::assertStringContainsString('loading="eager"', $html);
        self::assertStringContainsString('decoding="sync"', $html);
    }

    public function testAltIsHtmlEscaped(): void
    {
        $html = (new PassthroughRenderer())->render($this->svg(), alt: 'A & B "quoted"');

        self::assertStringContainsString('alt="A &amp; B &quot;quoted&quot;"', $html);
    }
}
