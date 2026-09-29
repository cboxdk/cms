<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Consistency\ProjectionState;
use Cbox\Cms\Contracts\Consistency\TransactionRequired;
use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Envelope\GenerationModel;
use Cbox\Cms\Contracts\Envelope\IssuerKind as EnvelopeIssuer;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Envelope\ModelParameter;
use Cbox\Cms\Contracts\Envelope\PromptReference;
use Cbox\Cms\Contracts\Envelope\Provenance;
use Cbox\Cms\Contracts\Envelope\SourceReference;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Identity\Adapter\PostgresActorVersionLock;
use Cbox\Cms\Core\Pipeline\Adapter\PostgresChangesetCommitter;
use Cbox\Cms\Core\Pipeline\Domain\ChangesetCommitter;
use Cbox\Cms\Core\Pipeline\Domain\CommitOutcome;
use Cbox\Cms\Core\Pipeline\Domain\Dto\PendingChangeset;
use Cbox\Cms\Core\Pipeline\Domain\LockStrength;
use Cbox\Cms\Core\Pipeline\Domain\UncommittableChangeset;
use Cbox\Cms\Core\Tests\Pipeline\Tally\AddTally;
use Cbox\Cms\Core\Tests\Pipeline\Tally\TallyTable;
use Cbox\Cms\Core\Tests\Pipeline\Tally\TallyWorld;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/*
 * The commit on Postgres (PRD 6.2 phase 7, 7.3, 8.4, GUARDRAILS 4.1), through the real command
 * pipeline with the test-only command tally.add and its test-only mutation writer: the changeset,
 * its principals and reason text, its audit row, the mutations, the events, the idempotency record
 * and the receipt commit together, and a failure anywhere leaves none of them.
 */

afterEach(function (): void {
    TallyWorld::cleanUp();
});

/**
 * Drops the managed partition of the table for the world's day, as the owner role. The next test
 * covers it again.
 */
function dropCommitPartition(string $table): void
{
    DB::connection('pgsql_owner')->statement(sprintf('drop table if exists %s_p20260310', $table));
}

/**
 * @return list<string>
 */
function commitTexts(string $sql): array
{
    return StorageTables::texts(StorageTables::superuser(), $sql);
}

it('is the container\'s commit', function (): void {
    expect(app(ChangesetCommitter::class))->toBeInstanceOf(PostgresChangesetCommitter::class);
});

it('commits the changeset, its principals, reason, audit, mutations, events, idempotency record and receipt together', function (): void {
    $world = new TallyWorld;
    $deputy = $world->identity->addActor(ActorClass::Staff)->id;

    $result = $world->add(new AddTally(TallyWorld::tally(), 3), 'commit-1', TallyWorld::reason(), $deputy);

    expect($result->outcome())->toBe(Outcome::Committed);
    $receipt = $result->receipt;
    $changeset = $receipt->changesetId;
    expect($changeset)->toBeInstanceOf(ChangesetId::class);
    $id = $changeset instanceof ChangesetId ? $changeset->toString() : '';

    expect(TallyWorld::rows())->toBe([
        'changeset_register' => 1, 'changesets' => 1, 'changeset_principals' => 1, 'changeset_reason_texts' => 1, 'audit' => 1,
        'events' => 1, 'idempotency_keys' => 1, 'receipts' => 1, 'receipt_projections' => 1, TallyTable::TABLE => 1,
    ])
        ->and(TallyTable::row(TallyWorld::tally()))->toBe([1, 3])
        ->and(array_map(static fn (ProjectionStatus $status): string => $status->projection->value.' '.$status->state->value, $receipt->projections))->toBe([TallyWorld::PROJECTION.' '.ProjectionState::Pending->value])
        ->and(commitTexts("select concat_ws(' ', changeset_id, retention_class) as value from changeset_register"))->toBe([$id.' standard'])
        ->and(commitTexts("select concat_ws(' ', command, command_version, actor_id, issuer_kind, surface, reason_code, idempotency_key, correlation_id, format_version, created_at) as value from changesets"))
        ->toBe([sprintf('tally.add 1 %s human rest correction commit-1 tally-correlation 1 2026-03-10 12:00:00+00', $world->actor->toString())])
        ->and(commitTexts("select concat_ws(' ', position, actor_id) as value from changeset_principals"))->toBe(['1 '.$deputy->toString()])
        ->and(commitTexts("select concat_ws(' ', classification, text) as value from changeset_reason_texts"))->toBe(['personal Asked by the desk.'])
        ->and(commitTexts("select concat_ws(' ', actor_id, command, command_version, issuer_kind, surface, reason_code, coalesce(legal_basis, '-'), aggregates::text) as value from audit"))
        ->toBe([sprintf('%s tally.add 1 human rest correction - {tally:%s}', $world->actor->toString(), TallyWorld::TALLY)])
        ->and(commitTexts("select concat_ws(' ', changeset_id, stream, aggregate_type, aggregate_id, aggregate_version, type, type_version) as value from events"))
        ->toBe([sprintf('%s interactive tally %s 1 tally.raised 1', $id, TallyWorld::TALLY)])
        ->and(commitTexts("select changeset_id::text as value from idempotency_keys where idempotency_key = 'commit-1'"))->toBe([$id]);

    // One commit position: the changeset's, the audit's, the events' and the receipt's.
    expect(commitTexts('select distinct xid::text as value from (select xid from changesets union all select xid from audit union all select xid from events union all select position from receipts) as p'))
        ->toBe([$receipt->position->value ?? '']);
    expect($world->tallyLock->locked)->toBe(['tally:'.TallyWorld::TALLY.' update']);
});

it('stores the provenance of an agent\'s change in the changeset row, any text included', function (): void {
    $world = new TallyWorld;
    $world->provenance = new Provenance(
        new GenerationModel('drafter', '2026-09'),
        [new ModelParameter('temperature', '0.2'), new ModelParameter('style', 'say "hi", \\ {x}')],
        new PromptReference('prompts/tally.md'),
        [new SourceReference('https://example.test/a,b'), new SourceReference('memo "7"')],
    );

    $result = $world->add(new AddTally(TallyWorld::tally(), 1), 'provenance-1');

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and(commitTexts("select concat_ws(' | ', provenance_model, provenance_model_version, provenance_parameters::text, provenance_prompt, provenance_sources::text) as value from changesets"))
        ->toBe(['drafter | 2026-09 | {"style": "say \\"hi\\", \\\\ {x}", "temperature": "0.2"} | prompts/tally.md | ["https://example.test/a,b", "memo \\"7\\""]']);
});

it('stores no provenance for a change without one', function (): void {
    (new TallyWorld)->add(new AddTally(TallyWorld::tally(), 1), 'no-provenance-1');

    expect(commitTexts("select concat_ws(' ', coalesce(provenance_model, '-'), coalesce(provenance_parameters::text, '-'), coalesce(provenance_sources::text, '-')) as value from changesets"))->toBe(['- - -']);
});

it('raises the version of an aggregate by one per changeset, and its event carries the version', function (): void {
    $world = new TallyWorld;

    $world->add(new AddTally(TallyWorld::tally(), 3), 'raise-1');
    $second = $world->add(new AddTally(TallyWorld::tally(), 4), 'raise-2');

    expect($second->outcome())->toBe(Outcome::Committed)
        ->and(TallyTable::row(TallyWorld::tally()))->toBe([2, 7])
        ->and(commitTexts('select aggregate_version::text as value from events order by event_id'))->toBe(['1', '2']);
});

it('writes the events of a seed on the bulk stream', function (): void {
    $result = new TallyWorld()->seed(new AddTally(TallyWorld::tally(), 1), 'seed-unit-1');

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and(commitTexts("select concat_ws(' ', stream, issuer_kind) as value from events join changesets using (changeset_id)"))->toBe(['bulk seed']);
});

it('rolls back everything when a mutation writer fails after an earlier mutation was written, and leaves the key fresh', function (): void {
    $world = new TallyWorld;
    $world->refuse = TallyWorld::other();

    expect(fn (): WriteResult => $world->add(new AddTally(TallyWorld::tally(), 1, TallyWorld::other()), 'fails-1'))
        ->toThrow(RuntimeException::class, 'The tally writer refuses the tally '.TallyWorld::OTHER.'.');

    expect(TallyWorld::rows())->toBe(TallyWorld::nothing());

    $world->refuse = null;
    $retry = $world->add(new AddTally(TallyWorld::tally(), 1, TallyWorld::other()), 'fails-1');

    expect($retry->outcome())->toBe(Outcome::Committed)
        ->and(TallyTable::row(TallyWorld::tally()))->toBe([1, 1])
        ->and(TallyTable::row(TallyWorld::other()))->toBe([1, 1])
        ->and(commitTexts('select aggregates::text as value from audit'))->toBe([sprintf('{tally:%s,tally:%s}', TallyWorld::TALLY, TallyWorld::OTHER)]);
});

it('answers partition_missing and keeps nothing when no partition takes the receipt, after the events were written', function (): void {
    $world = new TallyWorld;
    dropCommitPartition('receipts_standard');

    $result = $world->add(new AddTally(TallyWorld::tally(), 1), 'no-receipt-1');

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and($result->errors[0]->code->value ?? null)->toBe('partition_missing')
        ->and($result->errors[0]->message ?? '')->toContain('receipts_standard')
        ->and($result->receipt->changesetId)->toBeNull()
        ->and(TallyWorld::rows())->toBe(TallyWorld::nothing());
});

it('answers partition_missing and keeps nothing, the receipt included, when no partition takes the idempotency record', function (): void {
    $world = new TallyWorld;
    dropCommitPartition('idempotency_keys');

    $result = $world->add(new AddTally(TallyWorld::tally(), 1), 'no-key-1');

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and($result->errors[0]->code->value ?? null)->toBe('partition_missing')
        ->and(TallyWorld::rows())->toBe(TallyWorld::nothing());
});

it('answers partition_missing and keeps nothing when no partition takes the changeset', function (): void {
    $world = new TallyWorld;
    dropCommitPartition('audit');

    $result = $world->add(new AddTally(TallyWorld::tally(), 1), 'no-audit-1');

    expect($result->errors[0]->code->value ?? null)->toBe('partition_missing')
        ->and(TallyWorld::rows())->toBe(TallyWorld::nothing());
});

it('rejects the call with version_conflict when the actor changed after it was read, and keeps nothing', function (): void {
    $world = new TallyWorld;
    $world->meanwhile = static function () use ($world): void {
        $world->identity->changeState($world->actor, ActorState::Deactivated);
    };

    $result = $world->add(new AddTally(TallyWorld::tally(), 1), 'actor-changed-1');

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(array_map(static fn (CatalogError $error): string => $error->code->value.' '.$error->message, $result->errors))
        ->toBe([sprintf('version_conflict The aggregate "actor:%s" changed after it was read: expected version 1, found version 2.', $world->actor->toString())])
        ->and(TallyWorld::rows())->toBe(TallyWorld::nothing());
});

it('rejects the call with version_conflict when another session created an aggregate the command read as absent', function (): void {
    $world = new TallyWorld;
    $world->meanwhile = static function (): void {
        TallyTable::put(TallyWorld::tally(), 1, 5);
    };

    $result = $world->add(new AddTally(TallyWorld::tally(), 1), 'created-meanwhile-1');

    expect(array_map(static fn (CatalogError $error): string => $error->code->value.' '.$error->message, $result->errors))
        ->toBe([sprintf('version_conflict The aggregate "tally:%s" changed after it was read: expected no aggregate, found version 1.', TallyWorld::TALLY)])
        ->and(TallyTable::row(TallyWorld::tally()))->toBe([1, 5])
        ->and(array_diff_key(TallyWorld::rows(), [TallyTable::TABLE => true]))->toBe(array_diff_key(TallyWorld::nothing(), [TallyTable::TABLE => true]));
});

it('rejects the call with version_conflict when another session changed an aggregate after it was read', function (): void {
    $world = new TallyWorld;
    TallyTable::put(TallyWorld::tally(), 1, 5);
    $world->meanwhile = static function (): void {
        TallyTable::put(TallyWorld::tally(), 2, 6);
    };

    $result = $world->add(new AddTally(TallyWorld::tally(), 1), 'changed-meanwhile-1');

    expect($result->errors[0]->message ?? '')->toBe(sprintf('The aggregate "tally:%s" changed after it was read: expected version 1, found version 2.', TallyWorld::TALLY))
        ->and(TallyTable::row(TallyWorld::tally()))->toBe([2, 6])
        ->and(TallyWorld::rows()['changesets'])->toBe(0);
});

it('refuses an event a writer returns about another version, and keeps nothing', function (): void {
    $world = new TallyWorld;
    $world->eventVersionOffset = 1;

    expect(fn (): WriteResult => $world->add(new AddTally(TallyWorld::tally(), 1), 'foreign-event-1'))
        ->toThrow(UncommittableChangeset::class, 'returned the event');

    expect(TallyWorld::rows())->toBe(TallyWorld::nothing());
});

it('refuses a plan without mutations', function (): void {
    $world = new TallyWorld;
    $db = DB::connection();
    $envelope = Envelope::external(IssuingSurface::Rest, EnvelopeIssuer::Human, $world->actor, new IdempotencyKey('empty-1'), new CorrelationId('tally-empty'));
    $access = new AccessContext(new ActorPrincipal($world->actor, [], IssuerKind::Service, ClassificationAccess::Sensitive), [], ClassificationAccess::Internal);
    $db->beginTransaction();

    try {
        expect(fn (): CommitOutcome => $world->committer()->commit(new PendingChangeset(new CommandName('tally.add'), 1, new AddTally(TallyWorld::tally(), 1), $envelope, $access, new Plan, new ReadVersions)))
            ->toThrow(UncommittableChangeset::class, 'The plan of the command tally.add has no mutation');
    } finally {
        $db->rollBack();
    }
});

it('refuses to commit outside a transaction and writes nothing', function (): void {
    $world = new TallyWorld;
    $envelope = Envelope::external(IssuingSurface::Rest, EnvelopeIssuer::Human, $world->actor, new IdempotencyKey('outside-1'), new CorrelationId('tally-outside'));
    $access = new AccessContext(new ActorPrincipal($world->actor, [], IssuerKind::Service, ClassificationAccess::Sensitive), [], ClassificationAccess::Internal);
    $reads = new ReadVersions(ReadVersion::at($world->actor, AggregateVersion::first()), ReadVersion::absent(TallyWorld::tally()));

    expect(fn (): CommitOutcome => $world->committer()->commit(new PendingChangeset(new CommandName('tally.add'), 1, new AddTally(TallyWorld::tally(), 1), $envelope, $access, new Plan, $reads)))
        ->toThrow(TransactionRequired::class, 'A changeset is committed inside the caller\'s command transaction');

    expect(TallyWorld::rows())->toBe(TallyWorld::nothing());
});

it('locks an actor through the owner\'s lookup until the transaction ends and reads its version, or null for an unknown actor', function (): void {
    $world = new TallyWorld;
    $lock = new PostgresActorVersionLock(app(ConnectionResolverInterface::class));
    $db = DB::connection();
    $owner = DB::connection('pgsql_owner');
    $blocked = static function () use ($owner, $world): string {
        try {
            $owner->select('select id from actors where id = ? for update nowait', [$world->actor->toString()]);

            return 'free';
        } catch (QueryException $exception) {
            return str_contains($exception->getMessage(), '55P03') ? 'locked' : $exception->getMessage();
        }
    };
    $db->beginTransaction();

    try {
        $share = $lock->lock($world->actor, LockStrength::Share);
        $whileShared = $blocked();
        $update = $lock->lock($world->actor, LockStrength::Update);
        $unknown = $lock->lock(ActorId::fromString(TallyWorld::OTHER), LockStrength::Share);
    } finally {
        $db->rollBack();
    }

    expect($share?->value)->toBe(1)
        ->and($whileShared)->toBe('locked')
        ->and($update?->value)->toBe(1)
        ->and($unknown)->toBeNull()
        ->and($blocked())->toBe('free')
        ->and($lock->kind())->toBe('actor');
});
