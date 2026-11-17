<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Builder;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Ynamite\Media\Builder\ImageBuilder;
use Ynamite\Media\Config;
use Ynamite\Media\Pipeline\Preloader;
use Ynamite\Media\Pipeline\MetadataReader;
use Ynamite\Media\Source\MediapoolSourceFactory;
use Ynamite\Media\Source\ExternalManifest;
use Ynamite\Media\Source\ExternalSource;

final class ImageBuilderTest extends TestCase
{
    private string $tmpBase;

    protected function setUp(): void
    {
        $this->tmpBase = sys_get_temp_dir() . '/massif_builder_' . uniqid('', true);
        mkdir($this->tmpBase . '/media', 0777, true);
        \rex_path::_setBase($this->tmpBase);
        \rex_config::set(Config::ADDON, Config::KEY_SIGN_KEY, 'builder-test');
        Preloader::reset();
    }

    protected function tearDown(): void
    {
        \rex_dir::delete($this->tmpBase, true);
        \rex_config::_reset();
        Preloader::reset();
    }

    #[DataProvider('passthroughFixtures')]
    public function testPassthroughUrlMarkupAndPreloadUseOriginalCacheToken(string $fixture): void
    {
        copy(__DIR__ . '/../../_fixtures/' . $fixture, $this->tmpBase . '/media/' . $fixture);
        touch($this->tmpBase . '/media/' . $fixture, 1_700_000_000);
        $builder = (new ImageBuilder($fixture))->preload()->fetchPriority('high')->focal('25% 75%');

        $html = $builder->render();

        self::assertSame('/media/' . $fixture . '?v=1700000000', $builder->url());
        self::assertStringContainsString('src="/media/' . $fixture . '?v=1700000000"', $html);
        self::assertStringContainsString('fetchpriority="high"', $html);
        self::assertStringContainsString('style="object-position:25% 75%"', $html);
        $preload = Preloader::drain();
        if (str_starts_with($fixture, 'animated-')) {
            self::assertStringContainsString('as="image" imagesrcset="', $preload);
            self::assertStringContainsString('type="image/webp"', $preload);
            self::assertStringContainsString('imagesizes="', $preload);
            self::assertStringNotContainsString('href=', $preload);
        } else {
            self::assertStringContainsString('as="image" href="/media/' . $fixture . '?v=1700000000"', $preload);
            self::assertStringNotContainsString('imagesrcset=', $preload);
        }
        self::assertSame('', Preloader::drain());
    }

    public static function passthroughFixtures(): array
    {
        return [['vector.svg'], ['tiny-32x32.gif'], ['animated-3frame.gif'], ['animated-700.webp']];
    }

    public function testPassthroughPreloadIsOptIn(): void
    {
        copy(__DIR__ . '/../../_fixtures/vector.svg', $this->tmpBase . '/media/logo.svg');
        (new ImageBuilder('logo.svg'))->render();
        self::assertSame('', Preloader::drain());
    }

    public function testUnavailableWebpPreloadsOriginalAnimation(): void
    {
        copy(__DIR__ . '/../../_fixtures/animated-3frame.gif', $this->tmpBase . '/media/animation.gif');
        $capability = new \ReflectionProperty(Config::class, 'serverFormatCapability');
        $previous = $capability->getValue();
        $capability->setValue(null, ['webp' => false]);
        try {
            $html = (new ImageBuilder('animation.gif'))->preload()->render();
            $preload = Preloader::drain();

            self::assertStringStartsWith('<img', $html);
            self::assertStringContainsString('href="/media/animation.gif?v=', $preload);
            self::assertStringNotContainsString('imagesrcset', $preload);
            self::assertStringNotContainsString('image/webp', $preload);
        } finally {
            $capability->setValue(null, $previous);
        }
    }

    public function testPassthroughPreloadQueuesOriginalUrl(): void
    {
        copy(__DIR__ . '/../../_fixtures/vector.svg', $this->tmpBase . '/media/logo.svg');
        touch($this->tmpBase . '/media/logo.svg', 1_700_000_000);
        (new ImageBuilder('logo.svg'))->preload()->render();

        self::assertStringContainsString('as="image" href="/media/logo.svg?v=1700000000"', Preloader::drain());
    }

    public function testPassthroughForwardsFetchPriority(): void
    {
        copy(__DIR__ . '/../../_fixtures/vector.svg', $this->tmpBase . '/media/logo.svg');
        $html = (new ImageBuilder('logo.svg'))->fetchPriority('high')->render();
        self::assertStringContainsString('fetchpriority="high"', $html);
    }

    public function testAnimatedSizesReachPassthroughSource(): void
    {
        copy(__DIR__ . '/../../_fixtures/animated-3frame.gif', $this->tmpBase . '/media/animation.gif');
        $source = (new MediapoolSourceFactory())->resolve('animation.gif');
        $path = MetadataReader::metaCachePath($source);
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, json_encode([
            'width' => 1600, 'height' => 900, 'mime' => 'image/gif', 'source_format' => 'gif',
            'focal' => null, 'is_animated' => true, 'frames' => 3, 'failed' => false,
        ]));

        $html = (new ImageBuilder('animation.gif'))->sizes('50vw')->render();

        self::assertStringContainsString('<source type="image/webp"', $html);
        self::assertStringContainsString('sizes="50vw"', $html);
        self::assertStringContainsString(' 640w', $html);
    }

    public function testExternalPassthroughUrlAndPreloadAreUnchanged(): void
    {
        $url = 'http://1.1.1.1/animation.gif?a=1&v=original';
        $hash = ExternalSource::hashFor($url);
        $origin = ExternalManifest::originPath($hash);
        @mkdir(dirname($origin), 0777, true);
        copy(__DIR__ . '/../../_fixtures/animated-3frame.gif', $origin);
        ExternalManifest::write($hash, ['url' => $url, 'etag' => null, 'lastModified' => null, 'fetchedAt' => time(), 'ttl' => 86400]);
        \rex_config::set(Config::ADDON, 'asset_host', 'https://assets.example.com');
        $builder = (new ImageBuilder($url))->preload();

        self::assertSame($url, $builder->url());
        self::assertStringStartsWith('<img src="http://1.1.1.1/animation.gif?a=1&amp;v=original"', $builder->render());
        self::assertStringContainsString('href="http://1.1.1.1/animation.gif?a=1&amp;v=original"', Preloader::drain());
    }

    private function buildAndExtractFilters(callable $configure): array
    {
        $b = new ImageBuilder('test.jpg');
        $configure($b);

        $reflection = new \ReflectionProperty($b, 'filterParams');
        return $reflection->getValue($b);
    }

    public function testBrightnessClampsRange(): void
    {
        $f = $this->buildAndExtractFilters(fn (ImageBuilder $b) => $b->brightness(200));
        self::assertSame(['bri' => 100], $f);
    }

    public function testContrastClampsRange(): void
    {
        $f = $this->buildAndExtractFilters(fn (ImageBuilder $b) => $b->contrast(-200));
        self::assertSame(['con' => -100], $f);
    }

    public function testGammaClampsAsFloat(): void
    {
        $f = $this->buildAndExtractFilters(fn (ImageBuilder $b) => $b->gamma(15.0));
        self::assertSame(['gam' => 9.99], $f);
    }

    public function testSharpenInRange(): void
    {
        $f = $this->buildAndExtractFilters(fn (ImageBuilder $b) => $b->sharpen(20));
        self::assertSame(['sharp' => 20], $f);
    }

    public function testFilterPresetPassthrough(): void
    {
        $f = $this->buildAndExtractFilters(fn (ImageBuilder $b) => $b->filter('sepia'));
        self::assertSame(['filt' => 'sepia'], $f);
    }

    public function testBgValidatesHex(): void
    {
        $valid = $this->buildAndExtractFilters(fn (ImageBuilder $b) => $b->bg('ff00cc'));
        self::assertSame(['bg' => 'ff00cc'], $valid);

        $invalid = $this->buildAndExtractFilters(fn (ImageBuilder $b) => $b->bg('xyz'));
        self::assertSame([], $invalid);
    }

    public function testBorderComposesString(): void
    {
        $f = $this->buildAndExtractFilters(fn (ImageBuilder $b) => $b->border(2, 'ff0000', 'expand'));
        self::assertSame(['border' => '2,ff0000,expand'], $f);
    }

    public function testFlipPassthrough(): void
    {
        $f = $this->buildAndExtractFilters(fn (ImageBuilder $b) => $b->flip('h'));
        self::assertSame(['flip' => 'h'], $f);
    }

    public function testWatermarkComposesAllSubParams(): void
    {
        $f = $this->buildAndExtractFilters(fn (ImageBuilder $b) => $b->watermark(
            src: 'logo.png',
            size: 0.25,
            position: 'bottom-right',
            padding: 20,
            alpha: 70,
        ));

        self::assertSame('logo.png', $f['mark']);
        self::assertSame(0.25, $f['marks']);
        self::assertSame('bottom-right', $f['markpos']);
        self::assertSame(20, $f['markpad']);
        self::assertSame(70, $f['markalpha']);
    }

    public function testFiltersBulkApplier(): void
    {
        $f = $this->buildAndExtractFilters(fn (ImageBuilder $b) => $b->filters([
            'brightness' => 10,
            'sharpen' => 20,
            'bogus' => 'value',
        ]));
        self::assertSame(['bri' => 10, 'sharp' => 20], $f);
    }

    public function testChainingMergesFilters(): void
    {
        $f = $this->buildAndExtractFilters(fn (ImageBuilder $b) =>
            $b->brightness(10)->sharpen(20)->filter('sepia')
        );
        self::assertSame(['bri' => 10, 'sharp' => 20, 'filt' => 'sepia'], $f);
    }
}
