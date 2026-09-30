<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Core\Fragments\Actions\InvalidateFragments;
use Cbox\Cms\Core\Pipeline\Actions\AwaitWaitLevel;
use Cbox\Cms\Core\Pipeline\Boundary\WaitConfig;
use Cbox\Cms\Core\Pipeline\Domain\Dto\WaitSettings;
use Cbox\Cms\Core\Pipeline\Domain\WaitLevelRule;
use DateTimeImmutable;
use Illuminate\Config\Repository;
use InvalidArgumentException;

/*
 * When a changeset has reached a wait level (PRD 8.4), and how long a command waits for it after
 * commit, cbox-cms.receipts.wait_budget_ms.
 */

function levelStatus(string $projection, bool $acknowledged): ProjectionStatus
{
    return $acknowledged
        ? ProjectionStatus::acknowledged(new ProjectionName($projection), new DateTimeImmutable('2026-03-10T12:00:00Z'))
        : ProjectionStatus::pending(new ProjectionName($projection));
}

it('decides each wait level from the projection statuses of a receipt', function (WaitLevel $level, bool $reached, ProjectionStatus ...$statuses): void {
    expect(WaitLevelRule::reached($level, array_values($statuses)))->toBe($reached);
})->with([
    'commit with origin pending' => [WaitLevel::Commit, true, levelStatus('origin', false)],
    'origin with nothing listed' => [WaitLevel::Origin, true],
    'origin pending' => [WaitLevel::Origin, false, levelStatus('origin', false)],
    'origin acknowledged' => [WaitLevel::Origin, true, levelStatus('origin', true)],
    'origin acknowledged, another pending' => [WaitLevel::Origin, true, levelStatus('origin', true), levelStatus('search', false)],
    'origin not listed, another pending' => [WaitLevel::Origin, true, levelStatus('search', false)],
    'edge with another pending' => [WaitLevel::Edge, false, levelStatus('origin', true), levelStatus('search', false)],
    'verified with every one acknowledged' => [WaitLevel::Verified, true, levelStatus('origin', true), levelStatus('search', true)],
    'propagated with nothing listed' => [WaitLevel::Propagated, true],
    'propagated with origin pending' => [WaitLevel::Propagated, false, levelStatus('origin', false)],
]);

it('names the projection the invalidation subscriber acknowledges as the one origin waits for', function (): void {
    expect(InvalidateFragments::PROJECTION)->toBe(WaitLevelRule::ORIGIN)
        ->and(WaitLevelRule::ORIGIN)->toBe('origin');
});

it('reads the default of the core package, 5 seconds', function (): void {
    $settings = WaitConfig::read(new Repository(['cbox-cms' => require __DIR__.'/../../config/cbox-cms.php']));

    expect($settings->budgetMilliseconds)->toBe(5000)
        ->and(WaitConfig::DEFAULT_WAIT_BUDGET_MS)->toBe(5000)
        ->and(WaitConfig::read(new Repository([]))->budgetMilliseconds)->toBe(WaitConfig::DEFAULT_WAIT_BUDGET_MS);
});

it('reads a budget from 0 to the most a call may wait', function (int $milliseconds): void {
    expect(WaitConfig::read(new Repository(['cbox-cms' => ['receipts' => ['wait_budget_ms' => $milliseconds]]]))->budgetMilliseconds)->toBe($milliseconds);
})->with([0, 1, 250, WaitSettings::MAX_MILLISECONDS]);

it('refuses a budget that is not a whole number from 0 to 30000', function (mixed $value, string $shown): void {
    expect(fn (): WaitSettings => WaitConfig::read(new Repository(['cbox-cms' => ['receipts' => ['wait_budget_ms' => $value]]])))
        ->toThrow(InvalidArgumentException::class, "The setting cbox-cms.receipts.wait_budget_ms must be a whole number of milliseconds from 0 to 30000; it is {$shown}.");
})->with([
    'below zero' => [-1, '-1'],
    'above the cap' => [30_001, '30001'],
    'a string' => ['5000', 'string'],
    'a float' => [5000.0, 'float'],
    'null' => [null, 'null'],
]);

it('refuses settings outside 0 to 30000', function (int $milliseconds): void {
    expect(fn (): WaitSettings => new WaitSettings($milliseconds))
        ->toThrow(InvalidArgumentException::class, "A wait budget is 0 to 30000 ms; it is {$milliseconds}.");
})->with([-1, 30_001]);

it('binds the settings in the container from the configuration, and the wait after commit with them', function (): void {
    expect(app(WaitSettings::class)->budgetMilliseconds)->toBe(5000);

    config()->set('cbox-cms.receipts.wait_budget_ms', 750);

    expect(app(WaitSettings::class)->budgetMilliseconds)->toBe(750)
        ->and(app(AwaitWaitLevel::class))->toBeInstanceOf(AwaitWaitLevel::class);
});
