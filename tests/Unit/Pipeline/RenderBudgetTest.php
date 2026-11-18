<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Unit\Pipeline;

use PHPUnit\Framework\TestCase;
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
}
