<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\ReceiptStore\Adapter;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Consistency\DuplicateReceipt;
use Cbox\Cms\Contracts\Consistency\ProjectionState;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\Uuid7;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\StoredReceipt;
use Cbox\Cms\Contracts\ReceiptStore;
use Cbox\Cms\Contracts\Storage\PartitionMissing;
use Cbox\Cms\Core\Partitions\Adapter\MissingPartitionMapper;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;

/**
 * The receipt store on Postgres (PRD 4, 4.1, 8.4), as the app role, through the query builder.
 *
 * It runs every statement on the caller's connection, the default connection unless one is named,
 * and never begins, commits or rolls back a transaction (GUARDRAILS 4.1, PRD 4.2). Inside the
 * command transaction the receipt commits and rolls back with the changeset. It makes no call
 * outside Postgres.
 *
 * The rows live in `receipts`, one per changeset, and `receipt_projections`, one per changeset and
 * projection; see the migration for the partitions. They hold the facts of the changeset and no
 * call's outcome or wait level: store() runs in the command transaction, before any wait. markProjection() updates the one row of its
 * projection, so workers that mark different projections of a changeset never wait for each
 * other, and the row lock orders workers that mark the same one. The update only changes a
 * pending row, so the first acknowledgement stays.
 *
 * One receipt per changeset: the primary key is (changeset_id, retention_class), because a key on
 * a partitioned table must hold the LIST partition key, so it cannot refuse a second receipt of the
 * other class. store() therefore first takes an advisory lock on the changeset (ReceiptLock), then
 * looks for a receipt of either class, then inserts with ON CONFLICT DO NOTHING. Inside the
 * caller's transaction the lock is transaction-scoped: a concurrent store of the same changeset
 * waits for it until the first transaction ends, and its lookup, a new statement under READ
 * COMMITTED, the command transaction's level, sees the committed receipt. Under SERIALIZABLE the
 * second transaction fails to serialise instead. Without a transaction a transaction-scoped lock
 * would end with its own statement, before the lookup and the inserts, so store() takes the
 * session-level lock on the same key and releases it after the inserts, when each of them has
 * committed on its own; the next store's lookup then sees the receipt. The two forms share one
 * key, so a store inside a transaction and one outside wait for each other too. The lock is a
 * blocking wait, as the key wait of the insert was before it: the second store of a changeset is a
 * caller's error, not a path that is expected to wait.
 *
 * Expiry is logical, as the contract says: find() and markProjection() only match a Standard
 * receipt whose changeset id is at or after the lowest id that is still live at the Clock's time.
 * The partition manager drops the rows later, a week after their day ends.
 *
 * Every statement runs on the write PDO, the primary, also a select outside a transaction, where
 * Laravel would send it to a read host: a replica that has not replayed a receipt just stored would
 * make find() return null, markProjection() report that no live receipt lists the projection, and
 * store() miss a receipt of the other class. Stickiness does not cover it, because a zero-row
 * update marks no record as modified.
 *
 * A write at a date with no partition throws PartitionMissing.
 */
#[Experimental]
final readonly class PostgresReceiptStore implements ReceiptStore
{
    public const string RECEIPTS = 'receipts';

    public const string PROJECTIONS = 'receipt_projections';

    /** The transaction-scoped lock on a changeset's receipt, keyed by ReceiptLock. */
    public const string LOCK_CHANGESET = 'select pg_advisory_xact_lock(?)';

    /** The session-level lock on a changeset's receipt, for a store outside a transaction. */
    public const string LOCK_CHANGESET_SESSION = 'select pg_advisory_lock(?)';

    /** Releases the session-level lock once the store outside a transaction has committed. */
    public const string UNLOCK_CHANGESET_SESSION = 'select pg_advisory_unlock(?)';

    private const int MILLISECONDS_PER_DAY = 86_400_000;

    /**
     * @param  string|null  $connection  the connection name; null for the default connection, the one
     *                                   the command kernel opens its transaction on
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private Clock $clock,
        private ?string $connection = null,
    ) {}

    /**
     * @throws PartitionMissing when no partition covers the changeset's date
     */
    public function store(StoredReceipt $receipt): void
    {
        $db = $this->db();
        $lockKey = ReceiptLock::of($receipt->changesetId)->key;

        // The primary key covers one retention class, so only this lock keeps one receipt per
        // changeset across the classes: a second store of the changeset waits here until the first
        // has committed, and its lookup, a new statement, sees that receipt. Inside the caller's
        // transaction the lock lasts until the transaction ends. Without one each statement commits
        // on its own, so the lock must outlast the inserts: a session-level lock, released below.
        if ($db->transactionLevel() > 0) {
            $this->insert($db, $receipt, self::LOCK_CHANGESET, $lockKey);

            return;
        }

        try {
            $this->insert($db, $receipt, self::LOCK_CHANGESET_SESSION, $lockKey);
        } finally {
            $db->select(self::UNLOCK_CHANGESET_SESSION, [$lockKey], false);
        }
    }

    /**
     * Takes the lock with $lock, then looks for a receipt of the changeset and inserts it.
     *
     * @throws PartitionMissing when no partition covers the changeset's date
     */
    private function insert(ConnectionInterface $db, StoredReceipt $receipt, string $lock, int $lockKey): void
    {
        $changesetId = $receipt->changesetId;
        $id = $changesetId->toString();

        try {
            $db->select($lock, [$lockKey], false);

            // A receipt of either class for the changeset, expired or not, holds the changeset.
            if ($db->table(self::RECEIPTS)->useWritePdo()->where('changeset_id', $id)->exists()) {
                throw DuplicateReceipt::forChangeset($changesetId);
            }

            // ON CONFLICT DO NOTHING: a duplicate is reported without aborting the caller's transaction.
            $inserted = $db->table(self::RECEIPTS)->insertOrIgnore([
                'changeset_id' => $id,
                'retention_class' => $receipt->retentionClass->value,
            ]);

            if ($inserted === 0) {
                throw DuplicateReceipt::forChangeset($changesetId);
            }

            if ($receipt->projections !== []) {
                $db->table(self::PROJECTIONS)->insert(array_map(
                    fn (ProjectionStatus $status): array => [
                        'changeset_id' => $id,
                        'retention_class' => $receipt->retentionClass->value,
                        'projection' => $status->projection->value,
                        'state' => $status->state->value,
                        'acknowledged_at' => $this->timestamp($status->acknowledgedAt),
                    ],
                    $receipt->projections,
                ));
            }
        } catch (QueryException $exception) {
            throw MissingPartitionMapper::map($exception);
        }
    }

    public function find(ChangesetId $changesetId): ?StoredReceipt
    {
        $db = $this->db();
        $id = $changesetId->toString();

        $receipt = $db->table(self::RECEIPTS)
            ->useWritePdo()
            ->select(['changeset_id', 'retention_class'])
            ->where('changeset_id', $id)
            ->where($this->live())
            ->first();

        if ($receipt === null) {
            return null;
        }

        $projections = $db->table(self::PROJECTIONS)
            ->useWritePdo()
            ->select(['projection', 'state', 'acknowledged_at'])
            ->where('changeset_id', $id)
            ->where('retention_class', ReceiptRows::retentionClassOf($receipt))
            ->orderBy('projection')
            ->get()
            ->all();

        return ReceiptRows::receipt($receipt, $projections);
    }

    public function markProjection(ChangesetId $changesetId, ProjectionStatus $status): bool
    {
        $row = fn (): Builder => $this->db()->table(self::PROJECTIONS)
            ->useWritePdo()
            ->where('changeset_id', $changesetId->toString())
            ->where('projection', $status->projection->value)
            ->where($this->live());

        if ($status->state === ProjectionState::Acknowledged) {
            $updated = $row()
                ->where('state', ProjectionState::Pending->value)
                ->update([
                    'state' => ProjectionState::Acknowledged->value,
                    'acknowledged_at' => $this->timestamp($status->acknowledgedAt),
                ]);

            if ($updated > 0) {
                return true;
            }
        }

        return $row()->exists();
    }

    /**
     * The lowest changeset id whose Standard receipt is live at $now: a receipt is live up to and
     * including its changeset's milliseconds plus the retention, so the lowest live millisecond is
     * $now rounded up to the millisecond, minus the retention.
     */
    public static function lowestLiveStandard(DateTimeImmutable $now): Uuid7
    {
        $microseconds = (int) $now->format('U') * 1_000_000 + (int) $now->format('u');
        $milliseconds = intdiv($microseconds, 1000) + ($microseconds % 1000 > 0 ? 1 : 0);

        return Uuid7::lowestAt(max(0, $milliseconds - RetentionClass::STANDARD_DAYS * self::MILLISECONDS_PER_DAY));
    }

    /**
     * The Clock-based window as a where clause: every Evidence row, and Standard rows from the
     * lowest live changeset id.
     *
     * @return Closure(Builder): void
     */
    private function live(): Closure
    {
        $lowest = self::lowestLiveStandard($this->clock->now())->value;

        return static function (Builder $query) use ($lowest): void {
            $query->where('retention_class', RetentionClass::Evidence->value)
                ->orWhere('changeset_id', '>=', $lowest);
        };
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->connection);
    }

    private function timestamp(?DateTimeImmutable $time): ?string
    {
        return $time?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }
}
