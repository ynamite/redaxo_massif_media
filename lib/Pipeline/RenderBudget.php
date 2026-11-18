<?php

declare(strict_types=1);

namespace Ynamite\Media\Pipeline;

use rex_file;

/**
 * Per-request time budget for placeholder generation during render.
 *
 * Dominant colour and LQIP are inlined into the markup, so a cold cache used
 * to make the page wait for one image decode per image (seconds on a page
 * with dozens of images). Both generators run their cache-miss work through
 * {@see run()}: once the time spent generating in this request reaches
 * BUDGET_MS, further misses return '' (the renderer's "skip" value) and a
 * later request fills them in.
 *
 * It bounds when a generation may START, not how long it runs — a decode
 * can't be interrupted. The first miss of a request always runs, so every
 * request makes progress. Cache hits never go through here.
 *
 * Static state = per request under FPM/mod_php. A CLI process has no TTFB to
 * protect and may render many pages in one run, so it is unlimited unless
 * {@see reset()} starts a budgeted job. The upload hook (boot.php) bypasses
 * the budget with {@see unlimited()}; bypassed work is not charged.
 *
 * Slow failures: a generation that fails writes no cache file, so it would be
 * retried on every request. When such a failure is slow it would use up the
 * budget each time and starve every miss after it on the page. A failure
 * that took SLOW_FAILURE_MS or more therefore leaves a `<cachePath>.failed`
 * marker and is not retried for FAILURE_RETRY_SECONDS. Fast failures (no
 * Imagick, unreadable source) cost nothing and are simply retried.
 */
final class RenderBudget
{
    public const BUDGET_MS = 200;
    public const SLOW_FAILURE_MS = 50;
    public const FAILURE_RETRY_SECONDS = 3600;

    private static float $spentMs = 0.0;
    /** null = not decided yet: unlimited in CLI, budgeted otherwise. */
    private static ?bool $unlimited = null;

    /**
     * @param callable(): string $generate returns '' on failure
     * @param string|null $cachePath the sidecar this generation would write;
     *   enables the slow-failure marker next to it
     */
    public static function run(callable $generate, ?string $cachePath = null): string
    {
        $failedMarker = $cachePath === null ? null : $cachePath . '.failed';
        if (
            $failedMarker !== null
            && is_file($failedMarker)
            && (int) filemtime($failedMarker) > time() - self::FAILURE_RETRY_SECONDS
        ) {
            return '';
        }

        $unlimited = self::isUnlimited();
        if (!$unlimited && self::$spentMs >= self::BUDGET_MS) {
            return '';
        }

        $start = hrtime(true);
        $result = '';
        try {
            return $result = $generate();
        } finally {
            $elapsedMs = (hrtime(true) - $start) / 1_000_000;
            if (!$unlimited) {
                self::$spentMs += $elapsedMs;
            }
            if ($result === '' && $failedMarker !== null && $elapsedMs >= self::SLOW_FAILURE_MS) {
                rex_file::put($failedMarker, '');
            }
        }
    }

    /**
     * @internal for tests: charge time without generating anything
     */
    public static function spend(float $ms): void
    {
        self::$spentMs += $ms;
    }

    /**
     * Run `$fn` with the budget bypassed (work that isn't on a render path).
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function unlimited(callable $fn): mixed
    {
        $previous = self::isUnlimited();
        self::$unlimited = true;
        try {
            return $fn();
        } finally {
            self::$unlimited = $previous;
        }
    }

    /**
     * Start a fresh budgeted job: nothing spent, bypass off (also in CLI —
     * the test suite relies on that).
     */
    public static function reset(): void
    {
        self::$spentMs = 0.0;
        self::$unlimited = false;
    }

    private static function isUnlimited(): bool
    {
        return self::$unlimited ??= PHP_SAPI === 'cli';
    }
}
