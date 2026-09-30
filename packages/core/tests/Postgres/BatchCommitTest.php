<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Envelope\IssuerKind as EnvelopeIssuer;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Core\Access\Infrastructure\ActorContext;
use Cbox\Cms\Core\Identity\Adapter\PostgresActorVersionLock;
use Cbox\Cms\Core\Pipeline\Adapter\PostgresChangesetCommitter;
use Cbox\Cms\Core\Pipeline\Domain\CommitOutcome;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Committed;
use Cbox\Cms\Core\Pipeline\Domain\Dto\PendingChangeset;
use Cbox\Cms\Core\Pipeline\Domain\Dto\StaleRead;
use Cbox\Cms\Core\Pipeline\Domain\Dto\VersionConflict;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriters;
use Cbox\Cms\Core\Pipeline\Domain\UncommittableChangeset;
use Cbox\Cms\Core\Pipeline\Domain\VersionLocks;
use Cbox\Cms\Core\ReceiptStore\Adapter\PostgresReceiptStore;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeAffectedProjections;
use Cbox\Cms\Core\Tests\Pipeline\Tally\AddTally;
use Cbox\Cms\Core\Tests\Pipeline\Tally\TallyAdded;
use Cbox\Cms\Core\Tests\Pipeline\Tally\TallyBatchLock;
use Cbox\Cms\Core\Tests\Pipeline\Tally\TallyBatchWriter;
use Cbox\Cms\Core\Tests\Pipeline\Tally\TallyId;
use Cbox\Cms\Core\Tests\Pipeline\Tally\TallyRaised;
use Cbox\Cms\Core\Tests\Pipeline\Tally\TallyWorld;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
 * The commit's runs (GUARDRAILS 4.1): the reads of one kind and strength are locked at once when
 * the kind's lock is a BatchVersionLock, with the advisory locks of the absent ones in one
 * statement, and mutations of one class that follow each other in the plan go to a
 * BatchMutationWriter at once; each event of a run is still held to its mutation's version.
 */

const BATCH_THIRD = '0192a0c0-0000-7000-8000-0000000000f3';

/**
 * Commits the plan of TallyAdded for the tallies, in order, each read at the version given (null
 * for absent), with the writer and the lock given, in a transaction it rolls back. Returns the
 * outcome and the statements it ran to lock the aggregates read as absent.
 *
 * @param  array<string, ?int>  $reads  by tally id
 * @param  list<string>  $plan  the tallies of the plan's mutations, in order
 * @return array{CommitOutcome, list<string>}
 */
function batchCommit(TallyWorld $world, TallyBatchWriter $writer, TallyBatchLock $lock, array $reads, array $plan): array
{
    $connections = app(ConnectionResolverInterface::class);
    $clock = new FakeClock(new DateTimeImmutable(TallyWorld::NOW));
    $committer = new PostgresChangesetCommitter(
        $connections,
        $clock,
        new FakeIdGenerator(seed: 3, clock: $clock),
        new VersionLocks(new PostgresActorVersionLock($connections), $lock),
        new MutationWriters($writer),
        new FakeAffectedProjections([TallyRaised::class => [new ProjectionName(TallyWorld::PROJECTION)]]),
        new PostgresReceiptStore($connections, $clock),
    );
    $versions = [ReadVersion::at($world->actor, AggregateVersion::first())];

    foreach ($reads as $tally => $version) {
        $versions[] = new ReadVersion(TallyId::fromString($tally), $version === null ? null : new AggregateVersion($version));
    }

    $envelope = Envelope::external(IssuingSurface::Rest, EnvelopeIssuer::Human, $world->actor, new IdempotencyKey('batch-1'), new CorrelationId('tally-batch'));
    $access = new AccessContext(new ActorPrincipal($world->actor, [], IssuerKind::Service, ClassificationAccess::Sensitive), [], ClassificationAccess::Internal);
    $advisory = [];
    DB::listen(static function (QueryExecuted $query) use (&$advisory): void {
        if (in_array($query->sql, [PostgresChangesetCommitter::LOCK_ABSENT, PostgresChangesetCommitter::LOCK_ABSENT_ALL], true)) {
            $advisory[] = $query->sql;
        }
    });
    $db = DB::connection();
    $db->beginTransaction();
    new ActorContext($connections)->set($access);

    try {
        return [$committer->commit(new PendingChangeset(
            new CommandName('tally.add'),
            1,
            new AddTally(TallyWorld::tally(), 1),
            $envelope,
            $access,
            new Plan(...array_map(static fn (string $tally): TallyAdded => new TallyAdded(TallyId::fromString($tally), 1), $plan)),
            new ReadVersions(...$versions),
        )), $advisory];
    } finally {
        $db->rollBack();
    }
}

afterEach(function (): void {
    TallyWorld::cleanUp();
});

it('locks a run of absent aggregates at once and writes a run of one class at once', function (): void {
    $world = new TallyWorld;
    $writer = new TallyBatchWriter;
    $lock = new TallyBatchLock;

    [$outcome, $advisory] = batchCommit($world, $writer, $lock, [TallyWorld::TALLY => null, TallyWorld::OTHER => null], [TallyWorld::TALLY, TallyWorld::OTHER]);

    expect($outcome)->toBeInstanceOf(Committed::class)
        ->and($writer->calls)->toBe([[TallyWorld::TALLY, TallyWorld::OTHER]])
        ->and($lock->calls)->toBe(['tally:'.TallyWorld::TALLY.',tally:'.TallyWorld::OTHER.' update'])
        ->and($advisory)->toHaveCount(1)
        ->and($advisory[0])->toBe(PostgresChangesetCommitter::LOCK_ABSENT_ALL);
});

it('writes and locks a run of one as a single mutation and a single aggregate', function (): void {
    $world = new TallyWorld;
    $writer = new TallyBatchWriter;
    $lock = new TallyBatchLock;

    [$outcome, $advisory] = batchCommit($world, $writer, $lock, [TallyWorld::TALLY => null], [TallyWorld::TALLY]);

    expect($outcome)->toBeInstanceOf(Committed::class)
        ->and($writer->calls)->toBe([[TallyWorld::TALLY]])
        ->and($lock->calls)->toBe(['tally:'.TallyWorld::TALLY.' update'])
        ->and($advisory)->toBe([PostgresChangesetCommitter::LOCK_ABSENT]);
});

it('splits the runs where the class of mutation or the kind and strength of a read changes', function (): void {
    $world = new TallyWorld;
    $writer = new TallyBatchWriter;
    $lock = new TallyBatchLock;

    [$outcome] = batchCommit($world, $writer, $lock, [TallyWorld::TALLY => null, TallyWorld::OTHER => null, BATCH_THIRD => null], [TallyWorld::TALLY, TallyWorld::OTHER]);

    expect($outcome)->toBeInstanceOf(Committed::class)
        ->and($lock->calls)->toBe(['tally:'.TallyWorld::TALLY.',tally:'.TallyWorld::OTHER.' update', 'tally:'.BATCH_THIRD.' share'])
        ->and($writer->calls)->toBe([[TallyWorld::TALLY, TallyWorld::OTHER]]);
});

it('answers VersionConflict with the stale reads of a run and writes nothing', function (): void {
    $world = new TallyWorld;
    $writer = new TallyBatchWriter;
    $lock = new TallyBatchLock;

    [$outcome] = batchCommit($world, $writer, $lock, [TallyWorld::TALLY => null, TallyWorld::OTHER => 4], [TallyWorld::TALLY, TallyWorld::OTHER]);

    expect($outcome)->toBeInstanceOf(VersionConflict::class)
        ->and($outcome instanceof VersionConflict ? array_map(static fn (StaleRead $stale): string => $stale->aggregate->aggregateKey(), $outcome->stale) : [])->toBe(['tally:'.TallyWorld::OTHER])
        ->and($writer->calls)->toBe([]);
});

it('refuses an event of a run about another version than its mutation\'s', function (): void {
    $world = new TallyWorld;

    expect(fn (): array => batchCommit($world, new TallyBatchWriter(lastEventVersionOffset: 1), new TallyBatchLock, [TallyWorld::TALLY => null, TallyWorld::OTHER => null], [TallyWorld::TALLY, TallyWorld::OTHER]))
        ->toThrow(UncommittableChangeset::class, 'returned the event');
});
