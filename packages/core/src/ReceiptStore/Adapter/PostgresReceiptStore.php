<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\ReceiptStore\Adapter;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Consistency\DuplicateReceipt;
use Cbox\Cms\Contracts\Consistency\ForeignPosition;
use Cbox\Cms\Contracts\Consistency\InvalidReceipt;
use Cbox\Cms\Contracts\Consistency\ProjectionState;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Consistency\TransactionRequired;
use Cbox\Cms\Contracts\Consistency\UnsupportedIsolation;
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
 * and never begins, commits or rolls back a transaction (GUARDRAILS 4.1, PRD 4.2). store() runs in
 * the command transaction, so the receipt commits and rolls back with the changeset. It makes no
 * call outside Postgres.
 *
 * The rows live in `receipts`, one per changeset with its commit position, and
 * `receipt_projections`, one per changeset and projection; see the migrations for the partitions. They hold the facts of the changeset and no
 * call's outcome or wait level: store() runs in the command transaction, before any wait.
 * markProjection() updates the one row of its projection, so workers that mark different
 * projections of a changeset never wait for each other, and the row lock orders workers that mark
 * the same one. The update only changes a pending row, so the first acknowledgement stays.
 *
 * One receipt per changeset: the primary key is (changeset_id, retention_class), because a key on
 * a partitioned table must hold the LIST partition key, so it cannot refuse a second receipt of the
 * other class. store() therefore first takes a transaction-scoped advisory lock on the changeset
 * (ReceiptLock), then looks for a receipt of either class, then inserts with ON CONFLICT DO
 * NOTHING. A concurrent store of the same changeset waits for the lock until the first transaction
 * ends, and its lookup, a new statement under READ COMMITTED, the command transaction's level, sees
 * the committed receipt. Under REPEATABLE READ or SERIALIZABLE the snapshot is taken by the
 * transaction's first statement, at the latest the lock statement itself, before it waits: the
 * lookup would miss the receipt committed during the wait, and the insert into the other class's
 * partition would store a second receipt. The lock statement therefore also reads the isolation
 * level and takes the lock only at READ COMMITTED; at any other level store() throws
 * UnsupportedIsolation, holding no lock and leaving the transaction usable. The lock is a blocking
 * wait, as the key wait of the insert was before it: the second store of a changeset is a caller's
 * error, not a path that is expected to wait.
 *
 * store() runs only inside the caller's transaction and throws TransactionRequired, before any
 * statement, without one. Outside a transaction a transaction-scoped lock would end with its own
 * statement, before the lookup and the insert, and a session-level lock is state a pooler in
 * transaction mode (PRD 5.10) does not keep: each statement may run on another server connection,
 * so the unlock can miss the backend that holds the lock, which then stays held for every later
 * store of the changeset. Inside a transaction the pooler keeps one server connection until the
 * transaction ends, and Postgres releases the lock then, so nothing outlives it.
 *
 * The receipt row and its projection rows are written by one statement (INSERT_RECEIPT, with a
 * data-modifying CTE per table), so they are stored or fail together: a projection row that no
 * partition covers fails the whole statement, and nothing of the receipt is stored. The projection
 * rows are inserted from the receipt row the CTE returns, so a receipt that ON CONFLICT DO NOTHING
 * skips writes none.
 *
 * Expiry is logical, as the contract says: find() and markProjection() only match a Standard
 * receipt whose changeset id is at or after the lowest id that is still live at the Clock's time.
 * The partition manager drops the rows later, a week after their day ends.
 *
 * Every statement runs on the write PDO, the primary, also a select of find() or markProjection()
 * outside a transaction, where Laravel would send it to a read host: a replica that has not
 * replayed a receipt just stored would make find() return null and markProjection() report that no
 * live receipt lists the projection. Stickiness does not cover it, because a zero-row update marks
 * no record as modified. Inside a transaction Laravel reads the primary anyway.
 *
 * The position. A receipt's position is the xid8 of the command transaction (CommitPosition), the
 * value the changeset row and its events carry. The lock statement also reads
 * pg_current_xact_id(), which gives the transaction its xid when it has none yet, and store()
 * refuses a receipt with another position with ForeignPosition, before it looks or inserts, so
 * nothing is stored. The position is stored as xid8 and read back as its decimal text.
 *
 * A write at a date with no partition throws PartitionMissing.
 */
#[Experimental]
final readonly class PostgresReceiptStore implements ReceiptStore
{
    public const string RECEIPTS = 'receipts';

    public const string PROJECTIONS = 'receipt_projections';

    /**
     * The transaction-scoped lock on a changeset's receipt, keyed by ReceiptLock, taken only when
     * the transaction's isolation level is the first binding, READ COMMITTED. It returns the level
     * and the transaction's commit position, its xid8 as text; CASE evaluates the lock only in its
     * branch, so at another level no lock is taken.
     */
    public const string LOCK_CHANGESET = "select current_setting('transaction_isolation') as isolation, pg_current_xact_id()::text as position, case when current_setting('transaction_isolation') = ? then pg_advisory_xact_lock(?) end as locked";

    /** The isolation level store() needs inside a transaction, as transaction_isolation names it. */
    public const string READ_COMMITTED = 'read committed';

    /**
     * Inserts the receipt row and, with {@see self::PROJECTION_ROWS} in place of %s, its projection
     * rows, in one statement. It returns the number of receipt rows inserted: 0 when ON CONFLICT DO
     * NOTHING skipped a receipt of the changeset and class.
     */
    public const string INSERT_RECEIPT = <<<'SQL'
        with receipt as (
            insert into "receipts" (changeset_id, retention_class, position)
            values (?, ?, ?::xid8)
            on conflict do nothing
            returning changeset_id, retention_class
        )%s
        select count(*) as inserted from receipt
        SQL;

    /**
     * The projection rows of INSERT_RECEIPT, one `(?::text, ?::text, ?::timestamptz)` per
     * projection in place of %s, inserted for the receipt row the statement inserted.
     */
    public const string PROJECTION_ROWS = <<<'SQL'
        , projections as (
            insert into "receipt_projections" (changeset_id, retention_class, projection, state, acknowledged_at)
            select receipt.changeset_id, receipt.retention_class, projection.name, projection.state, projection.acknowledged_at
            from receipt cross join (values %s) as projection (name, state, acknowledged_at)
        )
        SQL;

    private const string PROJECTION_ROW = '(?::text, ?::text, ?::timestamptz)';

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
     * @throws TransactionRequired when the connection has no transaction open
     * @throws PartitionMissing when no partition covers the changeset's date
     * @throws UnsupportedIsolation when the caller's transaction is not at READ COMMITTED; no lock
     *                              is taken and nothing is stored
     * @throws ForeignPosition when the receipt's position is not the transaction's; nothing is
     *                         stored
     */
    public function store(StoredReceipt $receipt): void
    {
        $db = $this->db();

        // Every statement runs in the caller's transaction, so the lock below lasts until that
        // transaction ends and no state of the store outlives it.
        if ($db->transactionLevel() < 1) {
            throw TransactionRequired::forStore();
        }

        $changesetId = $receipt->changesetId;
        $id = $changesetId->toString();

        try {
            // The primary key covers one retention class, so only this lock keeps one receipt per
            // changeset across the classes: a second store of the changeset waits here until the
            // first transaction has ended, and its lookup, a new statement, sees that receipt. The
            // lookup sees a receipt committed during the wait only with a new snapshot per
            // statement, READ COMMITTED, so the lock statement reads the level and takes the lock
            // only at that level: a refusal holds nothing.
            $row = $db->selectOne(
                self::LOCK_CHANGESET,
                [self::READ_COMMITTED, ReceiptLock::of($changesetId)->key],
                false,
            );
            $isolation = is_object($row) && property_exists($row, 'isolation') ? $row->isolation : null;

            if ($isolation !== self::READ_COMMITTED) {
                throw UnsupportedIsolation::receiptStore(is_string($isolation) ? $isolation : 'unknown');
            }

            $position = $this->transactionPosition($row);

            if (! $receipt->position->equals($position)) {
                throw ForeignPosition::forReceipt($changesetId, $receipt->position, $position);
            }

            // A receipt of either class for the changeset, expired or not, holds the changeset.
            if ($db->table(self::RECEIPTS)->useWritePdo()->where('changeset_id', $id)->exists()) {
                throw DuplicateReceipt::forChangeset($changesetId);
            }

            // One statement for the receipt and its projections, so they commit or fail together.
            // ON CONFLICT DO NOTHING: a duplicate is reported without aborting the caller's transaction.
            $bindings = [$id, $receipt->retentionClass->value, $receipt->position->value];
            $rows = [];

            foreach ($receipt->projections as $status) {
                $rows[] = self::PROJECTION_ROW;
                $bindings[] = $status->projection->value;
                $bindings[] = $status->state->value;
                $bindings[] = $this->timestamp($status->acknowledgedAt);
            }

            $projections = $rows === [] ? '' : sprintf(self::PROJECTION_ROWS, implode(', ', $rows));
            $result = $db->selectOne(sprintf(self::INSERT_RECEIPT, $projections), $bindings, false);

            if (! is_object($result) || ! property_exists($result, 'inserted') || $result->inserted !== 1) {
                throw DuplicateReceipt::forChangeset($changesetId);
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
            ->selectRaw('position::text as position')
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
     * The commit position the lock statement read, pg_current_xact_id() of the transaction.
     */
    private function transactionPosition(mixed $row): CommitPosition
    {
        $position = is_object($row) && property_exists($row, 'position') ? $row->position : null;

        try {
            return new CommitPosition(is_string($position) ? $position : '');
        } catch (InvalidReceipt $invalid) {
            throw UnreadableReceiptRow::refused(self::RECEIPTS, $invalid);
        }
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
