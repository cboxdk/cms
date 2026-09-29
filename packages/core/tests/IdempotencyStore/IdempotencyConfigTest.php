<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\IdempotencyStore;

use Cbox\Cms\Contracts\Idempotency\WaitBudget;
use Cbox\Cms\Core\IdempotencyStore\Boundary\IdempotencyConfig;
use Cbox\Cms\Core\IdempotencyStore\Domain\Dto\IdempotencySettings;
use Illuminate\Config\Repository;
use InvalidArgumentException;

/*
 * The kernel's default wait budget for an idempotency claim, cbox-cms.idempotency.wait_budget_ms
 * (PRD 6.1): after it, a command whose key another call holds is rejected with the retryable
 * idempotency_in_flight.
 */

it('reads the default of the core package, 2 of the command transaction\'s 5 seconds', function (): void {
    $settings = IdempotencyConfig::read(new Repository(['cbox-cms' => require __DIR__.'/../../config/cbox-cms.php']));

    expect($settings->waitBudget->milliseconds)->toBe(2000)
        ->and(IdempotencyConfig::DEFAULT_WAIT_BUDGET_MS)->toBe(2000);
});

it('uses the default when the key is not set', function (): void {
    expect(IdempotencyConfig::read(new Repository([]))->waitBudget->milliseconds)->toBe(IdempotencyConfig::DEFAULT_WAIT_BUDGET_MS);
});

it('reads a budget from 0 to the most a claim may wait', function (int $milliseconds): void {
    $settings = IdempotencyConfig::read(new Repository(['cbox-cms' => ['idempotency' => ['wait_budget_ms' => $milliseconds]]]));

    expect($settings->waitBudget->milliseconds)->toBe($milliseconds);
})->with([0, 1, 250, WaitBudget::MAX_MILLISECONDS]);

it('refuses a budget that is not a whole number from 0 to 5000', function (mixed $value, string $shown): void {
    expect(fn (): IdempotencySettings => IdempotencyConfig::read(new Repository(['cbox-cms' => ['idempotency' => ['wait_budget_ms' => $value]]])))
        ->toThrow(InvalidArgumentException::class, "The setting cbox-cms.idempotency.wait_budget_ms must be a whole number of milliseconds from 0 to 5000; it is {$shown}.");
})->with([
    'below zero' => [-1, '-1'],
    'above the cap' => [5001, '5001'],
    'a string' => ['2000', 'string'],
    'a float' => [2000.0, 'float'],
    'null' => [null, 'null'],
]);

it('binds the settings in the container from the configuration', function (): void {
    expect(app(IdempotencySettings::class)->waitBudget->milliseconds)->toBe(2000);

    config()->set('cbox-cms.idempotency.wait_budget_ms', 750);

    expect(app(IdempotencySettings::class)->waitBudget->milliseconds)->toBe(750);
});
