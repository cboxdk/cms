<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Subscriptions;

use Cbox\Cms\Core\Subscriptions\Domain\Dto\RunnerSettings;
use InvalidArgumentException;
use Throwable;

/*
 * The event runner's settings: their defaults, each bound taken and the value past it refused
 * with the setting's name and range, and the backoff that doubles up to its maximum.
 */

/**
 * The settings with one named argument set, or the refusal.
 */
function runnerSettingsWith(string $setting, int $value): RunnerSettings|Throwable
{
    try {
        return new RunnerSettings(null, ...[$setting => $value]);
    } catch (Throwable $thrown) {
        return $thrown;
    }
}

it('defaults to batches of 100 events within 1000 ms, 5 tries, a backoff from 100 to 5000 ms and an idle sleep of 200 ms', function (): void {
    $settings = new RunnerSettings(null);

    expect([$settings->serviceActor, $settings->batchSize, $settings->batchBudgetMs, $settings->maxAttempts, $settings->backoffBaseMs, $settings->backoffMaxMs, $settings->idleSleepMs])
        ->toBe([null, 100, 1000, 5, 100, 5000, 200]);
});

it('takes each setting at its bounds and refuses the value past each, naming the setting and its range', function (string $argument, string $name, int $minimum, int $maximum): void {
    $below = runnerSettingsWith($argument, $minimum - 1);
    $above = runnerSettingsWith($argument, $maximum + 1);

    expect(runnerSettingsWith($argument, $minimum))->toBeInstanceOf(RunnerSettings::class)
        ->and(runnerSettingsWith($argument, $maximum))->toBeInstanceOf(RunnerSettings::class)
        ->and($below)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($below instanceof Throwable ? $below->getMessage() : null)->toBe(sprintf('The setting cbox-cms.events.runner.%s must be a whole number from %d to %d; it is %d.', $name, $minimum, $maximum, $minimum - 1))
        ->and($above)->toBeInstanceOf(InvalidArgumentException::class);
})->with([
    'batch size' => ['batchSize', 'batch_size', 1, RunnerSettings::MAX_BATCH_SIZE],
    'batch budget' => ['batchBudgetMs', 'batch_budget_ms', 1, RunnerSettings::MAX_BATCH_BUDGET_MS],
    'tries' => ['maxAttempts', 'max_attempts', 1, RunnerSettings::MAX_ATTEMPTS],
    'idle sleep' => ['idleSleepMs', 'idle_sleep_ms', 1, RunnerSettings::MAX_WAIT_MS],
]);

it('takes a backoff base from 1 ms up to the maximum and refuses 0 and one above the maximum', function (): void {
    $zero = runnerSettingsWith('backoffBaseMs', 0);

    expect(new RunnerSettings(null, backoffBaseMs: 1)->backoffBaseMs)->toBe(1)
        ->and($zero instanceof Throwable ? $zero->getMessage() : null)->toBe('The setting cbox-cms.events.runner.backoff_base_ms must be a whole number from 1 to 60000; it is 0.')
        ->and(runnerSettingsWith('backoffBaseMs', 5001))->toBeInstanceOf(InvalidArgumentException::class)
        ->and(new RunnerSettings(null, backoffBaseMs: 5000)->backoff(3))->toBe(5000)
        ->and(new RunnerSettings(null, backoffBaseMs: 60000, backoffMaxMs: 60000)->backoffMaxMs)->toBe(60000)
        ->and(runnerSettingsWith('backoffMaxMs', 60001))->toBeInstanceOf(InvalidArgumentException::class);
});

it('waits the base after the first failure and doubles it after each further one up to the maximum', function (): void {
    $settings = new RunnerSettings(null, backoffBaseMs: 100, backoffMaxMs: 500);

    expect(array_map($settings->backoff(...), [1, 2, 3, 4, 10]))->toBe([100, 200, 400, 500, 500]);
});
