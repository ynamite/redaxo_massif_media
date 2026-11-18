<?php

declare(strict_types=1);

namespace Tests\Massif\Media\Helpers;

use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use Ynamite\Media\Pipeline\RenderBudget;

/**
 * RenderBudget is static per-request state. In one PHPUnit process it would
 * accumulate across tests and exhaust the budget in unrelated ones, so every
 * test starts with a fresh budget.
 */
final class ResetRenderBudgetExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new class implements PreparationStartedSubscriber {
            public function notify(PreparationStarted $event): void
            {
                RenderBudget::reset();
            }
        });
    }
}
