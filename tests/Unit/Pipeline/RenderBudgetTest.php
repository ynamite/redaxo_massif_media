<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Pipeline;

use PHPUnit\Framework\TestCase;
use rex_dir;
use rex_file;
use rex_path;
use Ynamite\Media\Pipeline\RenderBudget;

final class RenderBudgetTest extends TestCase
{
    public function testRunsGenerationWhileUnderBudget(): void
    {
        self::assertSame('x', RenderBudget::run(static fn (): string => 'x'));
    }

    public function testSkipsGenerationOnceBudgetSpent(): void
    {
        RenderBudget::spend(RenderBudget::BUDGET_MS);
        $called = false;
        $result = RenderBudget::run(static function () use (&$called): string {
            $called = true;
            return 'x';
        });
        self::assertSame('', $result);
        self::assertFalse($called);
    }

    public function testGenerationStartedUnderBudgetFinishesAndCountsItsTime(): void
    {
        RenderBudget::spend(RenderBudget::BUDGET_MS - 0.5);
        self::assertSame('x', RenderBudget::run(static function (): string {
            usleep(2000);
            return 'x';
        }));
        self::assertSame('', RenderBudget::run(static fn (): string => 'y'));
    }

    public function testUnlimitedBypassesBudgetOnlyInsideCallable(): void
    {
        RenderBudget::spend(RenderBudget::BUDGET_MS);
        $inside = RenderBudget::unlimited(static fn (): string => RenderBudget::run(static fn (): string => 'x'));
        self::assertSame('x', $inside);
        self::assertSame('', RenderBudget::run(static fn (): string => 'y'));
    }

    public function testUnlimitedRestoresBudgetWhenCallableThrows(): void
    {
        RenderBudget::spend(RenderBudget::BUDGET_MS);
        try {
            RenderBudget::unlimited(static function (): never {
                throw new \RuntimeException('boom');
            });
            self::fail('exception expected');
        } catch (\RuntimeException) {
        }
        self::assertSame('', RenderBudget::run(static fn (): string => 'y'));
    }

    public function testUnlimitedWorkIsNotChargedToBudget(): void
    {
        RenderBudget::spend(RenderBudget::BUDGET_MS - 0.5);
        RenderBudget::unlimited(static fn (): string => RenderBudget::run(static function (): string {
            usleep(2000);
            return 'x';
        }));
        self::assertSame('y', RenderBudget::run(static fn (): string => 'y'));
    }

    public function testCliProcessIsUnlimitedUntilReset(): void
    {
        // Fresh-process state (the suite's extension has already called
        // reset()). CLI has no TTFB to protect, and a batch job rendering
        // many pages must not stop generating after its first 200 ms.
        (new \ReflectionProperty(RenderBudget::class, 'unlimited'))->setValue(null, null);
        RenderBudget::spend(RenderBudget::BUDGET_MS);

        self::assertSame('x', RenderBudget::run(static fn (): string => 'x'));

        RenderBudget::reset();
        RenderBudget::spend(RenderBudget::BUDGET_MS);
        self::assertSame('', RenderBudget::run(static fn (): string => 'y'));
    }

    public function testSlowFailureIsNotRetriedUntilTheMarkerExpires(): void
    {
        // A generation that fails slowly writes no cache file. Retried on
        // every request it would use up the budget each time and starve the
        // misses after it — so it is parked behind a marker for an hour.
        $tmpBase = sys_get_temp_dir() . '/massif_budget_' . uniqid('', true);
        rex_path::_setBase($tmpBase);
        $cachePath = $tmpBase . '/cache/_color/ab/abcd.txt';
        $marker = RenderBudget::failedMarkerFor($cachePath);
        $slowFailure = static function (): string {
            usleep((RenderBudget::SLOW_FAILURE_MS + 10) * 1000);
            return '';
        };

        self::assertSame('', RenderBudget::run($slowFailure, $cachePath));
        self::assertFileExists($marker);
        // Markers live outside _color/ and _lqip/, so a clear that keeps
        // those directories still removes them.
        self::assertStringContainsString('/cache/_failed/', $marker);

        $called = false;
        $retry = static function () use (&$called): string {
            $called = true;
            return 'x';
        };
        self::assertSame('', RenderBudget::run($retry, $cachePath));
        self::assertFalse($called, 'a fresh marker must skip the generation');

        touch($marker, time() - RenderBudget::FAILURE_RETRY_SECONDS - 1);
        clearstatcache();
        self::assertSame('x', RenderBudget::run($retry, $cachePath));

        RenderBudget::forgetFailure($cachePath);
        self::assertFileDoesNotExist($marker);
        rex_dir::delete($tmpBase, true);
    }

    public function testSlowEmptyResultWithSidecarWrittenIsNotAFailure(): void
    {
        // "No colour" for a transparent image: '' is the answer, and the
        // sidecar remembers it.
        $tmpBase = sys_get_temp_dir() . '/massif_budget_' . uniqid('', true);
        rex_path::_setBase($tmpBase);
        $cachePath = $tmpBase . '/cache/_color/ab/abcd.txt';
        $slowNoColor = static function () use ($cachePath): string {
            usleep((RenderBudget::SLOW_FAILURE_MS + 10) * 1000);
            rex_file::put($cachePath, 'transparent');
            return '';
        };

        self::assertSame('', RenderBudget::run($slowNoColor, $cachePath));
        self::assertFileDoesNotExist(RenderBudget::failedMarkerFor($cachePath));
        rex_dir::delete($tmpBase, true);
    }

    public function testFastFailureWritesNoMarker(): void
    {
        $tmpBase = sys_get_temp_dir() . '/massif_budget_' . uniqid('', true);
        rex_path::_setBase($tmpBase);
        $cachePath = $tmpBase . '/cache/_color/ab/abcd.txt';

        self::assertSame('', RenderBudget::run(static fn (): string => '', $cachePath));
        self::assertFileDoesNotExist(RenderBudget::failedMarkerFor($cachePath));
    }
}
