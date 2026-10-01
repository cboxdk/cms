<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Envelope\UnitOfWork;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Idempotency\ClaimResult;
use Cbox\Cms\Contracts\Idempotency\Fresh;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Idempotency\Replay;
use Cbox\Cms\Contracts\Idempotency\WaitBudget;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\StoredReceipt;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Pipeline\Domain\CommandTransactionOpen;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCall;
use Cbox\Cms\Core\Pipeline\Domain\MissingReplayReceipt;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandAuthorizer;
use Cbox\Cms\Core\Tests\Pipeline\PipelineWorld;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbe;
use Cbox\Cms\Testkit\Idempotency\HolderEnd;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use DateInterval;

/*
 * The idempotency flow of the command pipeline (PRD 6.1, 8.4), with the testkit's fake
 * idempotency and receipt stores and the fake command transaction around each call (GUARDRAILS
 * 9): a fresh claim runs the command and completes the key with its changeset, a replay returns
 * the first call's receipt for the wait level it asks for and runs nothing, other content is
 * idempotency_conflict, and a key another call holds past the wait budget is
 * idempotency_in_flight. Internal issuers claim the key their envelope derived.
 */

/**
 * @return list<string> each error as its code
 */
function idempotencyErrors(WriteResult $result): array
{
    return array_map(static fn (CatalogError $error): string => $error->code->value, $result->errors);
}

/**
 * What a claim on the call's key and content finds in a transaction of its own, which it rolls back.
 */
function idempotencyClaim(PipelineWorld $world, CommandCall $call, ?RenameProbe $content = null): ClaimResult
{
    $command = new CommandName('probe.rename');
    $session = $world->keys->session();
    $session->begin();
    $claim = $session->claim(
        $call->envelope->idempotencyScope($command),
        $call->envelope->idempotencyKey,
        $world->hasher->hash($command, 1, $content ?? $call->command),
        WaitBudget::none(),
    );
    $session->rollBack();

    return $claim;
}

/**
 * Stores the receipt of a changeset in a transaction of its own.
 */
function idempotencyReceipt(PipelineWorld $world, ChangesetId $changeset): void
{
    $session = $world->receipts->session();
    $session->begin();
    $session->store(new StoredReceipt($changeset, RetentionClass::Standard, $session->position()));
    $session->commit();
}

it('runs a fresh key and completes it with the changeset in the same transaction', function (): void {
    $world = new PipelineWorld;
    $world->committing();
    $call = $world->keyed($world->command(), 'fresh-key');

    $result = $world->pipeline()->run($call);
    $changeset = $result->receipt->changesetId;
    $claim = idempotencyClaim($world, $call);

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($world->committer->pending)->toHaveCount(1)
        ->and($world->calls->methods())->toBe(['resolve', 'plan'])
        ->and($world->transaction->commits)->toBe(1)
        ->and($claim)->toBeInstanceOf(Replay::class)
        ->and($claim instanceof Replay && $changeset instanceof ChangesetId && $claim->changesetId->equals($changeset))->toBeTrue()
        ->and($changeset instanceof ChangesetId ? $world->receipts->find($changeset)?->changesetId->toString() : null)->toBe($changeset?->toString())
        ->and($world->keySession->inTransaction())->toBeFalse()
        ->and($world->receiptSession->inTransaction())->toBeFalse();
});

it('replays the original receipt for the same key and content and commits one changeset in five calls', function (): void {
    $world = new PipelineWorld;
    $world->committing();

    $results = array_map(
        static fn (int $attempt): WriteResult => $world->pipeline()->run($world->keyed($world->command(), 'repeated-key')),
        range(1, 5),
    );
    $first = $results[0]->receipt;

    expect($world->committer->pending)->toHaveCount(1)
        ->and($world->calls->methods())->toBe(['resolve', 'plan'])
        ->and($world->authorizer->asked)->toHaveCount(1)
        ->and($world->transaction->commits)->toBe(5)
        ->and(array_map(static fn (WriteResult $result): Outcome => $result->outcome(), $results))->each->toBe(Outcome::Committed)
        ->and(array_map(static fn (WriteResult $result): ?string => $result->receipt->changesetId?->toString(), $results))->each->toBe($first->changesetId?->toString())
        ->and($results[4]->receipt)->toEqual($first);
});

it('replays without resolving again, so an aggregate the first call changed is no conflict', function (): void {
    $world = new PipelineWorld;
    $world->committing();
    $command = $world->command(expected: new ReadVersions(ReadVersion::absent($world->entry())));

    $first = $world->pipeline()->run($world->keyed($command, 'create-once'));
    $world->shelf->put($world->entry(), new AggregateVersion(1), new AggregateVersion(1), new RevisionNumber(1));
    $again = $world->pipeline()->run($world->keyed($command, 'create-once'));
    $fresh = $world->pipeline()->run($world->keyed($command, 'another-key'));

    expect($first->outcome())->toBe(Outcome::Committed)
        ->and($again->outcome())->toBe(Outcome::Committed)
        ->and($again->receipt->changesetId?->toString())->toBe($first->receipt->changesetId?->toString())
        ->and(idempotencyErrors($fresh))->toBe(['version_conflict'])
        ->and($world->committer->pending)->toHaveCount(1);
});

it('builds the replayed receipt for the wait level the replay asks for', function (): void {
    $world = new PipelineWorld;
    $origin = new ProjectionName('origin');
    $world->committing(ProjectionStatus::pending($origin));

    $first = $world->pipeline()->run($world->keyed($world->command(), 'wait-key'));
    $pending = $world->pipeline()->run($world->keyed($world->command(), 'wait-key', WaitLevel::Origin));
    $atCommit = $world->pipeline()->run($world->keyed($world->command(), 'wait-key'));

    $changeset = $first->receipt->changesetId;
    expect($changeset)->toBeInstanceOf(ChangesetId::class);

    if ($changeset instanceof ChangesetId) {
        $world->receipts->markProjection($changeset, ProjectionStatus::acknowledged($origin, $world->clock->now()));
    }

    $acknowledged = $world->pipeline()->run($world->keyed($world->command(), 'wait-key', WaitLevel::Origin));

    expect($first->receipt->waitLevel)->toBe(WaitLevel::Commit)
        ->and($pending->outcome())->toBe(Outcome::CommittedWaitTimeout)
        ->and($pending->receipt->waitLevel)->toBe(WaitLevel::Origin)
        ->and($pending->receipt->projections)->toEqual([ProjectionStatus::pending($origin)])
        ->and($atCommit->outcome())->toBe(Outcome::Committed)
        ->and($atCommit->receipt->waitLevel)->toBe(WaitLevel::Commit)
        ->and($acknowledged->outcome())->toBe(Outcome::Committed)
        ->and($acknowledged->receipt->waitLevel)->toBe(WaitLevel::Origin)
        ->and($acknowledged->receipt->projections)->toEqual([ProjectionStatus::acknowledged($origin, $world->clock->now())])
        ->and($acknowledged->receipt->retentionClass)->toBe(RetentionClass::Standard)
        ->and($acknowledged->receipt->position)->toEqual($first->receipt->position)
        ->and($acknowledged->receipt->consistencyToken)->toBeNull()
        ->and($world->committer->pending)->toHaveCount(1);
});

it('replays a changeset with no projections as reached at every wait level', function (): void {
    $world = new PipelineWorld;
    $world->committing();

    $world->pipeline()->run($world->keyed($world->command(), 'plain-key'));
    $replay = $world->pipeline()->run($world->keyed($world->command(), 'plain-key', WaitLevel::Propagated));

    expect($replay->outcome())->toBe(Outcome::Committed)
        ->and($replay->receipt->waitLevel)->toBe(WaitLevel::Propagated)
        ->and($replay->receipt->projections)->toBe([]);
});

it('rejects the same key with other content as idempotency_conflict and keeps the first record', function (): void {
    $world = new PipelineWorld;
    $world->committing();
    $firstCall = $world->keyed($world->command(), 'conflict-key');

    $first = $world->pipeline()->run($firstCall);
    $other = $world->pipeline()->run($world->keyed($world->command(PipelineWorld::fields('Other')), 'conflict-key'));
    $claim = idempotencyClaim($world, $firstCall);

    expect($other->outcome())->toBe(Outcome::Rejected)
        ->and(idempotencyErrors($other))->toBe(['idempotency_conflict'])
        ->and($other->errors[0]->message)->toBe('The idempotency key "conflict-key" was used before for this command with other content.')
        ->and($other->receipt->changesetId)->toBeNull()
        ->and($other->receipt->retentionClass)->toBe(RetentionClass::Standard)
        ->and($world->committer->pending)->toHaveCount(1)
        ->and($world->calls->methods())->toBe(['resolve', 'plan'])
        ->and($world->transaction->rollBacks)->toBe(1)
        ->and($claim instanceof Replay ? $claim->changesetId->toString() : null)->toBe($first->receipt->changesetId?->toString());
});

it('rejects a key another call holds past the wait budget as idempotency_in_flight, which a client retries', function (): void {
    $world = new PipelineWorld;
    $world->committing();
    $call = $world->keyed($world->command(), 'held-key');
    $command = new CommandName('probe.rename');
    $world->keys->holdWhileWaiting(
        $call->envelope->idempotencyScope($command),
        $call->envelope->idempotencyKey,
        $world->hasher->hash($command, 1, $call->command),
        null,
        HolderEnd::Commit,
        PipelineWorld::BUDGET_MILLISECONDS + 1,
    );

    $started = hrtime(true);
    $result = $world->pipeline()->run($call);
    $waited = (hrtime(true) - $started) / 1_000_000;

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(idempotencyErrors($result))->toBe(['idempotency_in_flight'])
        ->and($result->errors[0]->message)->toBe(sprintf('Another call with the idempotency key "held-key" is still running after %d ms.', PipelineWorld::BUDGET_MILLISECONDS))
        ->and(ErrorCode::IdempotencyInFlight->entry()->retryable)->toBeTrue()
        ->and($waited)->toBeGreaterThanOrEqual(PipelineWorld::BUDGET_MILLISECONDS)
        ->and($world->keys->scheduledWaitEvents())->toBe(1)
        ->and($world->committer->pending)->toBe([])
        ->and($world->calls->calls)->toBe([])
        ->and($world->transaction->rollBacks)->toBe(1);
});

it('waits within the budget for the call that holds the key and then replays its result', function (): void {
    $world = new PipelineWorld;
    $world->committing();
    $call = $world->keyed($world->command(), 'awaited-key');
    $command = new CommandName('probe.rename');
    $changeset = new ChangesetId(new FakeIdGenerator(7, $world->clock)->next());
    idempotencyReceipt($world, $changeset);
    $world->keys->holdWhileWaiting(
        $call->envelope->idempotencyScope($command),
        $call->envelope->idempotencyKey,
        $world->hasher->hash($command, 1, $call->command),
        $changeset,
        HolderEnd::Commit,
        PipelineWorld::BUDGET_MILLISECONDS - 30,
    );

    $result = $world->pipeline()->run($call);

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($result->receipt->changesetId?->toString())->toBe($changeset->toString())
        ->and($world->keys->scheduledWaitEvents())->toBe(0)
        ->and($world->committer->pending)->toBe([]);
});

it('runs the command once the call that held the key rolls back within the budget', function (): void {
    $world = new PipelineWorld;
    $world->committing();
    $call = $world->keyed($world->command(), 'released-key');
    $command = new CommandName('probe.rename');
    $world->keys->holdWhileWaiting(
        $call->envelope->idempotencyScope($command),
        $call->envelope->idempotencyKey,
        $world->hasher->hash($command, 1, $call->command),
        null,
        HolderEnd::RollBack,
        PipelineWorld::BUDGET_MILLISECONDS - 30,
    );

    $result = $world->pipeline()->run($call);

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($world->committer->pending)->toHaveCount(1)
        ->and(idempotencyClaim($world, $call))->toBeInstanceOf(Replay::class);
});

it('leaves the key fresh when the call is rejected, so a retry runs again', function (): void {
    $world = new PipelineWorld;
    $world->committing();
    $world->refuse('The actor has no grant on the home node.');

    $refused = $world->pipeline()->run($world->keyed($world->command(), 'retry-key'));
    $afterRefusal = idempotencyClaim($world, $world->keyed($world->command(), 'retry-key'));
    $world->authorizer = new FakeCommandAuthorizer;
    $retried = $world->pipeline()->run($world->keyed($world->command(), 'retry-key'));

    expect(idempotencyErrors($refused))->toBe(['unauthorized'])
        ->and($afterRefusal)->toBeInstanceOf(Fresh::class)
        ->and($retried->outcome())->toBe(Outcome::Committed)
        ->and($world->committer->pending)->toHaveCount(1)
        ->and($world->transaction->rollBacks)->toBe(1)
        ->and($world->transaction->commits)->toBe(1);
});

it('keeps each actor\'s keys apart', function (): void {
    $world = new PipelineWorld;
    $world->committing();
    $other = $world->identity->addActor(ActorClass::Staff)->id;

    $mine = $world->pipeline()->run($world->keyed($world->command(), 'shared-key'));
    // Another entry, so the second command does not create the entry the first one created.
    $theirs = $world->pipeline()->run($world->keyed($world->command(entry: EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000f1')), 'shared-key', actor: $other));

    expect($mine->outcome())->toBe(Outcome::Committed)
        ->and($theirs->outcome())->toBe(Outcome::Committed)
        ->and($theirs->receipt->changesetId?->toString())->not->toBe($mine->receipt->changesetId?->toString())
        ->and($world->committer->pending)->toHaveCount(2);
});

it('claims nothing for a dry run, which neither replays nor completes the key', function (): void {
    $world = new PipelineWorld;
    $world->committing();

    $dryRun = $world->pipeline()->run($world->keyed($world->command(), 'dry-key', dryRun: true));
    $hashesAfterDryRun = $world->hasher->hashes;
    $committed = $world->pipeline()->run($world->keyed($world->command(), 'dry-key'));
    $dryAgain = $world->pipeline()->run($world->keyed($world->command(), 'dry-key', dryRun: true));

    expect($dryRun->outcome())->toBe(Outcome::DryRun)
        ->and($hashesAfterDryRun)->toBe(0)
        ->and($committed->outcome())->toBe(Outcome::Committed)
        ->and($dryAgain->outcome())->toBe(Outcome::DryRun)
        ->and($world->committer->pending)->toHaveCount(1)
        ->and($world->transaction->rollBacks)->toBe(2);
});

it('claims the key an internal issuer derived from its unit of work', function (): void {
    $world = new PipelineWorld;
    $world->committing();

    $first = $world->pipeline()->run($world->internal($world->command(), 'event:42:rename'));
    $again = $world->pipeline()->run($world->internal($world->command(), 'event:42:rename'));
    $otherUnit = $world->pipeline()->run($world->internal($world->command(), 'event:43:rename'));
    $derived = $world->keyed($world->command(), Envelope::deriveKey(IssuingSurface::Subscriber, new UnitOfWork('event:42:rename'))->value);

    expect($first->outcome())->toBe(Outcome::Committed)
        ->and($again->receipt->changesetId?->toString())->toBe($first->receipt->changesetId?->toString())
        ->and($otherUnit->receipt->changesetId?->toString())->not->toBe($first->receipt->changesetId?->toString())
        ->and($world->committer->pending)->toHaveCount(2)
        ->and($world->committer->pending[0]->envelope->idempotencyKey)->toEqual(new IdempotencyKey($derived->envelope->idempotencyKey->value))
        ->and(idempotencyClaim($world, $derived))->toBeInstanceOf(Replay::class);
});

it('rejects the call as partition_missing and rolls it all back when the key cannot be completed', function (): void {
    $world = new PipelineWorld;
    $world->committing();
    $now = $world->clock->now();
    $world->keys->uncover($now->sub(new DateInterval('P1D')), $now->add(new DateInterval('P1D')));
    $call = $world->keyed($world->command(), 'uncovered-key');

    $result = $world->pipeline()->run($call);

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(idempotencyErrors($result))->toBe([ErrorCode::PartitionMissing->value])
        ->and($result->receipt->changesetId)->toBeNull()
        ->and($world->committer->pending)->toHaveCount(1)
        ->and($world->receipts->committedRows())->toBe([])
        ->and($world->transaction->rollBacks)->toBe(1)
        ->and($world->transaction->commits)->toBe(0)
        ->and(idempotencyClaim($world, $call))->toBeInstanceOf(Fresh::class);
});

it('refuses a replay whose changeset has no receipt, and commits nothing', function (): void {
    $world = new PipelineWorld;
    $call = $world->keyed($world->command(), 'orphan-key');
    $command = new CommandName('probe.rename');
    $changeset = new ChangesetId(new FakeIdGenerator(9, $world->clock)->next());
    $session = $world->keys->session();
    $session->begin();
    $claim = $session->claim($call->envelope->idempotencyScope($command), $call->envelope->idempotencyKey, $world->hasher->hash($command, 1, $call->command), WaitBudget::none());

    if ($claim instanceof Fresh) {
        $session->complete($claim->token, $changeset);
    }

    $session->commit();

    expect(fn (): WriteResult => $world->pipeline()->run($call))
        ->toThrow(MissingReplayReceipt::class, sprintf('The idempotency record of this probe.rename call names the changeset %s, and the receipt store has no receipt for it.', $changeset->toString()));

    expect($world->transaction->rollBacks)->toBe(1)
        ->and($world->committer->pending)->toBe([]);
});

it('refuses a call inside an open transaction and runs nothing', function (): void {
    $world = new PipelineWorld;
    $world->keySession->begin();

    expect(fn (): WriteResult => $world->pipeline()->run($world->keyed($world->command(), 'nested-key')))
        ->toThrow(CommandTransactionOpen::class);

    expect($world->calls->calls)->toBe([])
        ->and($world->hasher->hashes)->toBe(0);
});
