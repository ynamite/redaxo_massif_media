<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Source;

use PHPUnit\Framework\TestCase;
use rex_path;
use Ynamite\Media\Source\ExternalManifest;

final class ExternalManifestTest extends TestCase
{
    private string $tmpBase;

    protected function setUp(): void
    {
        $this->tmpBase = sys_get_temp_dir() . '/massif_manifest_' . uniqid('', true);
        rex_path::_setBase($this->tmpBase);
    }

    protected function tearDown(): void
    {
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

    public function testReadMissingReturnsNull(): void
    {
        self::assertNull(ExternalManifest::read('abc123'));
    }

    public function testWriteThenReadRoundTrip(): void
    {
        $hash = 'abc123';
        ExternalManifest::write($hash, [
            'url' => 'https://images.example.com/hero.jpg',
            'etag' => '"abcdef"',
            'lastModified' => 1_700_000_000,
            'fetchedAt' => 1_700_000_500,
            'ttl' => 86_400,
        ]);

        $read = ExternalManifest::read($hash);

        self::assertNotNull($read);
        self::assertSame('https://images.example.com/hero.jpg', $read['url']);
        self::assertSame('"abcdef"', $read['etag']);
        self::assertSame(1_700_000_000, $read['lastModified']);
        self::assertSame(1_700_000_500, $read['fetchedAt']);
        self::assertSame(86_400, $read['ttl']);
    }

    public function testReadCorruptedJsonReturnsNull(): void
    {
        $hash = 'abc123';
        $path = ExternalManifest::manifestPath($hash);
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, '{not valid json');
        self::assertNull(ExternalManifest::read($hash));
    }

    public function testReadMissingRequiredFieldReturnsNull(): void
    {
        $hash = 'abc123';
        $path = ExternalManifest::manifestPath($hash);
        @mkdir(dirname($path), 0777, true);
        // Missing 'url' — incomplete manifest is treated as broken.
        file_put_contents($path, json_encode(['fetchedAt' => 1_700_000_000]));
        self::assertNull(ExternalManifest::read($hash));
    }

    public function testNullEtagRoundTripsAsNull(): void
    {
        $hash = 'noetag';
        ExternalManifest::write($hash, [
            'url' => 'https://example.com/foo.jpg',
            'etag' => null,
            'lastModified' => null,
            'fetchedAt' => 1_700_000_000,
            'ttl' => 86_400,
        ]);
        $read = ExternalManifest::read($hash);
        self::assertNotNull($read);
        self::assertNull($read['etag']);
        self::assertNull($read['lastModified']);
    }

    public function testPathLayoutMatchesContract(): void
    {
        // The directory shape is contractually `cache/_external/<hash>/...`
        // — Endpoint, CacheStats, and CacheInvalidator depend on it.
        self::assertStringContainsString('/cache/_external/abc123', ExternalManifest::bucketDir('abc123'));
        self::assertStringEndsWith('/_origin.bin', ExternalManifest::originPath('abc123'));
        self::assertStringEndsWith('/_manifest.json', ExternalManifest::manifestPath('abc123'));
    }
}
