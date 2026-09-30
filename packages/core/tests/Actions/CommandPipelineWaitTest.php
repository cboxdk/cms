<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Pipeline\Actions\AwaitWaitLevel;
use Cbox\Cms\Core\Pipeline\Domain\WaitLevelRule;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandAuthorizer;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\MeanwhilePacing;
use Cbox\Cms\Core\Tests\Pipeline\PipelineWorld;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Closure;
use PHPUnit\Framework\AssertionFailedError;

/*
 * The wait after commit (PRD 8.4, MILESTONES M1 point 5), with the testkit's fake receipt store
 * and real time that moves only when the wait sleeps (GUARDRAILS 9): commit returns at once,
 * origin once the origin projection on the receipt has acknowledged, and a level not reached within
 * the wait budget is committed_wait_timeout, committed but not waited out. Rejections, dry runs and
 * replays never wait.
 */

function waitOrigin(): ProjectionName
{
    return new ProjectionName(WaitLevelRule::ORIGIN);
}

/**
 * A world whose calls commit with these projections pending, and whose wait after commit has the
 * budget and sleeps on real time that runs $meanwhile with the world after the sleep numbered
 * $after.
 *
 * @param  list<ProjectionName>  $projections
 * @param  (Closure(PipelineWorld): void)|null  $meanwhile
 * @return array{PipelineWorld, MeanwhilePacing}
 */
function waitWorld(int $budget, array $projections, ?Closure $meanwhile = null, int $after = 1): array
{
    $world = new PipelineWorld;
    $world->committing(...array_map(ProjectionStatus::pending(...), $projections));
    $world->waitBudget = $budget;
    $pacing = new MeanwhilePacing($meanwhile instanceof Closure ? static fn () => $meanwhile($world) : null, $after);
    $world->pacing = $pacing;

    return [$world, $pacing];
}

/**
 * The changeset the world's first commit makes: the first id of its committer's generator.
 */
function waitFirstChangeset(PipelineWorld $world): ChangesetId
{
    return new ChangesetId(new FakeIdGenerator(clock: $world->clock)->next());
}

function waitAcknowledge(PipelineWorld $world, ProjectionName $projection): void
{
    if (! $world->receipts->markProjection(waitFirstChangeset($world), ProjectionStatus::acknowledged($projection, $world->clock->now()))) {
        throw new AssertionFailedError(sprintf('No live receipt lists the projection %s.', $projection->value));
    }
}

function waitCommitted(WriteResult $result): ChangesetId
{
    return $result->receipt->changesetId ?? throw new AssertionFailedError('The write did not commit.');
}

it('returns a call at wait level commit at once, without reading the receipt again', function (): void {
    [$world, $pacing] = waitWorld(1000, [waitOrigin()]);

    $result = $world->pipeline()->run($world->keyed($world->command(), 'commit-key'));

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($result->receipt->waitLevel)->toBe(WaitLevel::Commit)
        ->and($result->receipt->projections)->toEqual([ProjectionStatus::pending(waitOrigin())])
        ->and($pacing->sleeps())->toBe([]);
});

it('returns a call at wait level origin once the origin projection has acknowledged within the budget', function (): void {
    [$world, $pacing] = waitWorld(1000, [waitOrigin()], static fn (PipelineWorld $world) => waitAcknowledge($world, waitOrigin()), after: 3);

    $result = $world->pipeline()->run($world->keyed($world->command(), 'origin-key', WaitLevel::Origin));
    $stored = $world->receipts->find(waitCommitted($result));

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and(waitCommitted($result)->equals(waitFirstChangeset($world)))->toBeTrue()
        ->and($result->receipt->waitLevel)->toBe(WaitLevel::Origin)
        ->and($result->receipt->projections)->toEqual([ProjectionStatus::acknowledged(waitOrigin(), $world->clock->now())])
        ->and($result->receipt->position)->toEqual($stored?->position)
        ->and($pacing->sleeps())->toBe([AwaitWaitLevel::FIRST_PAUSE_MILLISECONDS, 4, 8])
        ->and($world->transaction->commits)->toBe(1);
});

it('returns committed_wait_timeout when origin is not acknowledged within the budget, with the changeset committed', function (): void {
    [$world, $pacing] = waitWorld(300, [waitOrigin()]);

    $result = $world->pipeline()->run($world->keyed($world->command(), 'timeout-key', WaitLevel::Origin));
    $stored = $world->receipts->find(waitCommitted($result));

    expect($result->outcome())->toBe(Outcome::CommittedWaitTimeout)
        ->and($result->errors)->toBe([])
        ->and($result->receipt->waitLevel)->toBe(WaitLevel::Origin)
        ->and($result->receipt->projections)->toEqual([ProjectionStatus::pending(waitOrigin())])
        ->and($stored?->changesetId->equals(waitCommitted($result)))->toBeTrue()
        ->and($world->transaction->commits)->toBe(1)
        ->and($world->committer->pending)->toHaveCount(1)
        ->and($pacing->sleeps())->toBe([2, 4, 8, 16, 32, 50, 50, 50, 50, 38])
        ->and(array_sum($pacing->sleeps()))->toBe(300);
});

it('never sleeps with a budget of 0 and returns committed_wait_timeout at once', function (): void {
    [$world, $pacing] = waitWorld(0, [waitOrigin()]);

    $result = $world->pipeline()->run($world->keyed($world->command(), 'no-wait-key', WaitLevel::Origin));

    expect($result->outcome())->toBe(Outcome::CommittedWaitTimeout)
        ->and($pacing->sleeps())->toBe([]);
});

it('has reached origin at commit when the receipt lists no origin projection', function (): void {
    [$world, $pacing] = waitWorld(1000, []);

    $result = $world->pipeline()->run($world->keyed($world->command(), 'nothing-key', WaitLevel::Origin));

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($result->receipt->projections)->toBe([])
        ->and($pacing->sleeps())->toBe([]);
});

it('waits at origin only for the origin projection, not the others the receipt lists', function (): void {
    $search = new ProjectionName('search');
    [$world, $pacing] = waitWorld(1000, [waitOrigin(), $search], static fn (PipelineWorld $world) => waitAcknowledge($world, waitOrigin()));

    $result = $world->pipeline()->run($world->keyed($world->command(), 'origin-only-key', WaitLevel::Origin));

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($result->receipt->projections)->toEqual([ProjectionStatus::acknowledged(waitOrigin(), $world->clock->now()), ProjectionStatus::pending($search)])
        ->and($pacing->sleeps())->toBe([2]);
});

it('waits past origin for every projection the receipt lists', function (): void {
    $search = new ProjectionName('search');
    [$world, $pacing] = waitWorld(100, [waitOrigin(), $search], static fn (PipelineWorld $world) => waitAcknowledge($world, waitOrigin()));

    $result = $world->pipeline()->run($world->keyed($world->command(), 'propagated-key', WaitLevel::Propagated));

    expect($result->outcome())->toBe(Outcome::CommittedWaitTimeout)
        ->and($result->receipt->projections)->toEqual([ProjectionStatus::acknowledged(waitOrigin(), $world->clock->now()), ProjectionStatus::pending($search)])
        ->and(array_sum($pacing->sleeps()))->toBe(100);
});

it('never waits for a rejected call or a dry run', function (): void {
    [$world, $pacing] = waitWorld(1000, [waitOrigin()]);

    $dryRun = $world->pipeline()->run($world->keyed($world->command(), 'dry-key', WaitLevel::Origin, dryRun: true));
    $world->authorizer = new FakeCommandAuthorizer('not in this test');
    $rejected = $world->pipeline()->run($world->keyed($world->command(), 'denied-key', WaitLevel::Origin));

    expect($dryRun->outcome())->toBe(Outcome::DryRun)
        ->and($rejected->outcome())->toBe(Outcome::Rejected)
        ->and($pacing->sleeps())->toBe([]);
});

it('never waits again for a replay, whose receipt is decided by the same rule', function (): void {
    [$world, $pacing] = waitWorld(0, [waitOrigin()]);

    $first = $world->pipeline()->run($world->keyed($world->command(), 'replayed-key', WaitLevel::Origin));
    $world->waitBudget = 1000;
    $pending = $world->pipeline()->run($world->keyed($world->command(), 'replayed-key', WaitLevel::Origin));
    waitAcknowledge($world, waitOrigin());
    $reached = $world->pipeline()->run($world->keyed($world->command(), 'replayed-key', WaitLevel::Origin));

    expect($first->outcome())->toBe(Outcome::CommittedWaitTimeout)
        ->and($pending->outcome())->toBe(Outcome::CommittedWaitTimeout)
        ->and($reached->outcome())->toBe(Outcome::Committed)
        ->and($reached->receipt->changesetId?->equals(waitCommitted($first)))->toBeTrue()
        ->and($world->committer->pending)->toHaveCount(1)
        ->and($pacing->sleeps())->toBe([]);
});
