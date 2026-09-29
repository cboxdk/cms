<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Consistency\TransactionRequired;
use Cbox\Cms\Contracts\Envelope\IssuerKind;
use Cbox\Cms\Contracts\Events\EventStream;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Contracts\Receipts\StoredReceipt;
use Cbox\Cms\Contracts\ReceiptStore;
use Cbox\Cms\Contracts\Storage\PartitionMissing;
use Cbox\Cms\Core\Changesets\Domain\Dto\ChangesetRecord;
use Cbox\Cms\Core\Changesets\Infrastructure\ChangesetWriter;
use Cbox\Cms\Core\Consistency\Infrastructure\TransactionPosition;
use Cbox\Cms\Core\Events\Infrastructure\EventWriter;
use Cbox\Cms\Core\Pipeline\Domain\AffectedProjections;
use Cbox\Cms\Core\Pipeline\Domain\ChangesetCommitter;
use Cbox\Cms\Core\Pipeline\Domain\CommitOutcome;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Committed;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Core\Pipeline\Domain\Dto\PendingChangeset;
use Cbox\Cms\Core\Pipeline\Domain\Dto\StaleRead;
use Cbox\Cms\Core\Pipeline\Domain\Dto\VersionConflict;
use Cbox\Cms\Core\Pipeline\Domain\LockStrength;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriters;
use Cbox\Cms\Core\Pipeline\Domain\UncommittableChangeset;
use Cbox\Cms\Core\Pipeline\Domain\VersionLocks;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use LogicException;
use Override;

/**
 * The commit on Postgres (PRD 6.2 phase 7, 7.3, 8.4, GUARDRAILS 4.1): one changeset, its
 * mutations, its audit, its events and its receipt, written in the command transaction on the
 * default connection, or the one named, as the app role under the actor context the command
 * transaction set. It never begins, ends or savepoints a transaction; the command transaction
 * commits what it wrote, and rolls it all back when the call is rejected or anything throws.
 *
 * 1. The version check (invariants 11 and 37). Every aggregate the command read, the actor and its
 *    on-behalf-of chain included, is locked through the VersionLock of its kind, one at a time in
 *    the order of the aggregate keys, so two commits lock the same aggregates in the same order: FOR
 *    UPDATE strength for an aggregate the plan changes, FOR SHARE for one it only read. An
 *    aggregate read as absent is first locked with its AggregateLock, so two commits that create it
 *    run one after the other. When any aggregate is not at the version it was read at, or no longer
 *    absent, the commit writes nothing and answers VersionConflict with every stale read.
 * 2. The changeset (ChangesetWriter): its register row with the retention class Standard, its
 *    metadata row with the commit position as the column default, its on-behalf-of chain, its
 *    reason's text and its one audit row, with the aggregates the plan changes.
 * 3. The mutations, in the plan's order, each through the MutationWriter of its class, with the
 *    version the changeset leaves its aggregate at: one above the version read, or 1 for an
 *    aggregate read as absent. Every event a writer returns must be about that version.
 * 4. The events, through the EventWriter, on the bulk stream for migrations and seeds and on the
 *    interactive stream for every other issuer (PRD 7.5).
 * 5. The receipt: the transaction's commit position (TransactionPosition) and the pending
 *    projections AffectedProjections gives for the events, stored in the ReceiptStore.
 *
 * A time no partition covers throws PartitionMissing from whichever write meets it; Postgres has
 * then failed the transaction, and the pipeline answers partition_missing. The changeset's id and
 * time come from the IdGenerator and the Clock.
 */
#[Internal]
final readonly class PostgresChangesetCommitter implements ChangesetCommitter
{
    /** The transaction-scoped lock on an aggregate read as absent. */
    public const string LOCK_ABSENT = 'select pg_advisory_xact_lock(?::bigint)';

    /** The retention class of every changeset the kernel commits in M1 (PRD 8.4). */
    public const RetentionClass RETENTION = RetentionClass::Standard;

    /**
     * @param  string|null  $connection  the connection name; null for the default connection, the one
     *                                   the command transaction runs on
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private Clock $clock,
        private IdGenerator $ids,
        private VersionLocks $locks,
        private MutationWriters $writers,
        private AffectedProjections $projections,
        private ReceiptStore $receipts,
        private ?string $connection = null,
    ) {}

    /**
     * @throws TransactionRequired when the connection has no transaction open; nothing is written
     * @throws UncommittableChangeset when the plan is empty or a mutation or aggregate has no writer or lock
     * @throws PartitionMissing when no partition covers the changeset or one of its events
     */
    #[Override]
    public function commit(PendingChangeset $changeset): CommitOutcome
    {
        $db = $this->connections->connection($this->connection);

        if ($db->transactionLevel() < 1) {
            throw TransactionRequired::forChangeset();
        }

        $mutations = $changeset->plan->mutations();

        if ($mutations === []) {
            throw UncommittableChangeset::emptyPlan($changeset->command->value);
        }

        $changed = [];

        foreach ($mutations as $mutation) {
            $changed[$mutation->aggregate()->aggregateKey()] ??= $mutation->aggregate();
        }

        $versions = [];
        $stale = [];

        foreach ($changeset->reads->reads as $read) {
            $key = $read->aggregate->aggregateKey();
            $current = $this->lock($db, $read->aggregate, $read->existed(), isset($changed[$key]) ? LockStrength::Update : LockStrength::Share);

            if (! $this->sameVersion($read->version, $current)) {
                $stale[] = new StaleRead($read->aggregate, $read->version, $current);
            }

            $versions[$key] = $read->version instanceof AggregateVersion ? $read->version->next() : AggregateVersion::first();
        }

        if ($stale !== []) {
            return new VersionConflict(...$stale);
        }

        $id = new ChangesetId($this->ids->next());
        $at = $this->clock->now();
        $envelope = $changeset->envelope;

        new ChangesetWriter($this->connections, $this->connection)->write(new ChangesetRecord(
            $id,
            $at,
            self::RETENTION,
            $changeset->command,
            $changeset->version,
            $envelope,
            array_values($changed),
        ));

        $events = [];

        foreach ($mutations as $mutation) {
            $version = $versions[$mutation->aggregate()->aggregateKey()] ?? throw $this->unread($mutation->aggregate());
            $writer = $this->writers->for($mutation);

            foreach ($writer->write($mutation, new MutationContext($id, $at, $envelope->actor, $version)) as $event) {
                if ($event->aggregate()->version !== $version->value) {
                    throw UncommittableChangeset::foreignEvent($writer::class, $event::class, $mutation->aggregate()->aggregateKey(), $version->value);
                }

                $events[] = $event;
            }
        }

        if ($events !== []) {
            new EventWriter($this->connections, $this->clock, $this->connection)->write($id, $this->stream($envelope->issuerKind), $events);
        }

        $position = new TransactionPosition($this->connections, $this->connection)->current();
        $projections = $this->projections->pendingFor($events);

        $this->receipts->store(new StoredReceipt($id, self::RETENTION, $position, $projections));

        return new Committed(Receipt::committed($id, $envelope->waitLevel, self::RETENTION, $position, $projections));
    }

    /**
     * The stream of a changeset's events (PRD 7.5): bulk for migrations and seeds, interactive for
     * people, agents, the scheduler, synchronisation and the system.
     */
    private function stream(IssuerKind $issuer): EventStream
    {
        return match ($issuer) {
            IssuerKind::Migration, IssuerKind::Seed => EventStream::Bulk,
            default => EventStream::Interactive,
        };
    }

    /**
     * Locks the aggregate and reads its version now.
     */
    private function lock(ConnectionInterface $db, AggregateRef $aggregate, bool $existed, LockStrength $strength): ?AggregateVersion
    {
        $lock = $this->locks->for($aggregate);

        if (! $existed) {
            $db->statement(self::LOCK_ABSENT, [AggregateLock::of($aggregate)->key]);
        }

        return $lock->lock($aggregate, $strength);
    }

    private function sameVersion(?AggregateVersion $read, ?AggregateVersion $current): bool
    {
        return $read instanceof AggregateVersion && $current instanceof AggregateVersion
            ? $read->equals($current)
            : $read === $current;
    }

    /**
     * The pipeline refuses a plan that changes an aggregate it did not read before the commit, so
     * this is a bug in the caller.
     */
    private function unread(AggregateRef $aggregate): LogicException
    {
        return new LogicException(sprintf('The plan changes the aggregate "%s", which the command did not read; the pipeline refuses such a plan before the commit.', $aggregate->aggregateKey()));
    }
}
