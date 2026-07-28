<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Install;

use PHPUnit\Framework\TestCase;
use Ynamite\Media\Install\PortablePath;

final class PortablePathTest extends TestCase
{
    private const FROM = '/home/vhost/site/public/assets/addons/massif_media/_img';

    public function testSiblingTreeTarget(): void
    {
        self::assertSame(
            "__DIR__ . '/../../../../../src/core/boot.php'",
            PortablePath::export(self::FROM, '/home/vhost/site/src/core/boot.php'),
        );
    }

    public function testAncestorTarget(): void
    {
        self::assertSame(
            "__DIR__ . '/../../../../'",
            PortablePath::export(self::FROM, '/home/vhost/site/public/'),
        );
    }

    public function testExpressionResolvesToTarget(): void
    {
        $expr = PortablePath::export(__DIR__, dirname(__DIR__, 2) . '/bootstrap.php');
        $path = eval('return ' . str_replace('__DIR__', var_export(__DIR__, true), $expr) . ';');
        self::assertSame(realpath(dirname(__DIR__, 2) . '/bootstrap.php'), realpath($path));
    }

    public function testNoCommonRootFallsBackToAbsolute(): void
    {
        self::assertSame(
            "'/Volumes/other/file.php'",
            PortablePath::export(self::FROM, '/Volumes/other/file.php'),
        );
    }
}
