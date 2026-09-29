---
title: Receipt store
weight: 34
description: "The ReceiptStore contract: one receipt per committed changeset, its commit position, projection status, expiry, the Postgres store, FakeReceiptStore and the shared suite ReceiptStoreContract."
---

# Receipt store

<!-- extension-point: Cbox\Cms\Contracts\ReceiptStore -->

The receipt store keeps one receipt for each committed changeset, with the status of each projection the changeset affected (PRD 4, 8.4). The command kernel stores the receipt in the command transaction, and the projections mark their status on it when they have caught up. A replay of an idempotent call (PRD 6.1) finds the receipt again through the store.

The contract is `Cbox\Cms\Contracts\ReceiptStore` in `cboxdk/cms`. It and the testkit types on this page are `#[Experimental]`: public API that an addon may use, without a compatibility promise yet, so it can change in a minor release.

| Method | What it does |
|---|---|
| `store(StoredReceipt $receipt): void` | Stores the receipt of a committed changeset, inside the caller's transaction. |
| `find(ChangesetId $changesetId): ?StoredReceipt` | The stored receipt, or null when there is none or it has expired. |
| `markProjection(ChangesetId $changesetId, ProjectionStatus $status): bool` | Records the status of one projection. Returns whether a live receipt lists the projection. |

## What is stored

A `StoredReceipt` (in `Cbox\Cms\Contracts\Receipts`) holds only facts about a committed changeset: its `ChangesetId`, its `RetentionClass`, its commit position and the `ProjectionStatus` of each projection, sorted by projection name. A projection is `pending`, or `acknowledged` with the time it acknowledged, in UTC with microseconds. A projection listed twice throws `InvalidReceipt`.

The command kernel stores the receipt with exactly the projections the changeset's events affect: the projection of each subscriber that receives one of the events and acknowledges on the receipt (`#[Subscription(projection: ...)]`, see [Subscribers](../subscribers.md)), each pending. A subscriber without a projection adds none, so a caller that waits past commit waits only for projections that will acknowledge.

## The commit position

The position (PRD 8.4, 8.12) is a `Cbox\Cms\Contracts\Consistency\CommitPosition`: the xid8 of the transaction that committed the changeset, `pg_current_xact_id()`, in decimal. The changeset row (its `xid` column), each of its events and its receipt carry the same value. A read has a position too: the xmin of its snapshot, `pg_snapshot_xmin(pg_current_snapshot())`. No transaction below the xmin is still running, so the rule is:

**A read at position R saw every changeset whose position is below R.**

It may have seen changesets at or above R as well; below R is what is certain. `$changeset->seenBy($read)` tells it, and `isBelow()` compares two positions by their numeric value. This is the same horizon the event log reads below (PRD 7.4): a subscriber that has read the events below xmin R has seen every changeset below R. The purge fence of PRD 8.12 compares a fragment's read position with a purge's position the same way.

The value is kept as a decimal string without leading zeros, because an xid8 is an unsigned 64-bit integer, larger than a PHP int holds and above what JSON readers keep exactly. It is not the consistency token of PRD 8.5, the WAL position of the commit, which a client sends with a later read to a replica; a `Receipt` carries both.

A receipt carries the position of the transaction that stores it. `store()` refuses any other position with `Cbox\Cms\Contracts\Consistency\ForeignPosition`, a `LogicException`, and stores nothing; the caller's transaction stays usable. On Postgres, `Cbox\Cms\Core\Consistency\Infrastructure\TransactionPosition` reads the position of the caller's open transaction, and throws `TransactionRequired` without one.

Only committed changesets are stored. A `StoredReceipt` cannot be made without a `ChangesetId`, and only a committed changeset has one, so a rejected call or a dry run has nothing to store. The `Receipt` a call returns, with its outcome and wait level, is never stored. The receipt is written in the command transaction, before any wait level past commit can be reached, so the kernel builds each call's `Receipt`, a replay's included, from the stored receipt, the wait level the call asks for and whether that level was reached.

A second receipt for a changeset throws `DuplicateReceipt` (in `Cbox\Cms\Contracts\Consistency`), whatever its retention class, and leaves the stored receipt as it was. A changeset has one receipt; mark its projections instead. When another open transaction has stored the changeset, `store()` waits until that transaction ends: it then throws `DuplicateReceipt` if the other transaction committed, and stores if it rolled back. The duplicate always comes from `store()`, never from the caller's commit.

## Transactions

`store()` and `markProjection()` run on the caller's connection. A store never begins, commits or rolls back a transaction, and never uses a savepoint (PRD 4.2).

`store()` runs only inside the caller's open transaction, the command transaction, so the receipt commits and rolls back with the changeset (PRD 6.2 phase 7). Without one it throws `Cbox\Cms\Contracts\Consistency\TransactionRequired`, a `LogicException`, and stores nothing. The transaction also holds what keeps one receipt per changeset, such as the Postgres store's lock on the changeset, and Postgres releases it when the transaction ends. A pooler in transaction mode (PRD 5.10) keeps one server connection for a transaction, but may run each statement outside one on another, so a lock held across such statements could be left behind on a server connection.

`markProjection()` runs inside the caller's transaction when one is open, and without one it commits on its own, as a subscriber calls it. `find()` reads on the same connection, so it sees the caller's uncommitted writes and no one else's.

`store()` on a database needs READ COMMITTED, the level of the command transaction. After waiting for another transaction that stored the changeset, it looks for that transaction's receipt, and only a new snapshot per statement shows it: under REPEATABLE READ or SERIALIZABLE the snapshot is older than the wait. The default store therefore throws `Cbox\Cms\Contracts\Consistency\UnsupportedIsolation` at those levels, before it waits, and stores nothing; the caller's transaction stays usable.

## Marking projections

`markProjection()` records the status of one projection; the status names the projection, and the statuses of the other projections are not touched. The call is idempotent, so a subscriber can repeat it after a crash. An acknowledgement is final: marking an acknowledged projection again, pending or acknowledged at another time, changes nothing, and the first time is kept.

It returns false, and changes nothing, when the store holds no live receipt for the changeset or the receipt does not list the projection. A projection that acknowledges after the receipt expired, or while it replays old events, is therefore not an error.

## Expiry

Expiry is logical and follows the `RetentionClass`. A `Standard` receipt is live up to and including `RetentionClass::Standard->expiresAt($changesetId)`, the time in the changeset id plus seven days (`RetentionClass::STANDARD_DAYS`), and expired once the `Clock` is later. From then on `find()` returns null and `markProjection()` returns false. An `Evidence` receipt never expires in the store; a policy decides when it goes.

Removing the rows is a separate job that drops whole partitions. Until it has run, an expired receipt still holds its changeset, so a second `store()` for it still throws `DuplicateReceipt`.

## Partitions

A store on a database keeps receipts in tables partitioned by the changeset's time, with no DEFAULT partition (PRD 4, 4.2). When no partition covers the changeset's time, `store()` throws `Cbox\Cms\Contracts\Storage\PartitionMissing`, with the error code `partition_missing`, and stores nothing. Inside a transaction the caller then rolls back, because the database has failed the transaction. The scheduled command `php artisan cms:partitions:maintain` creates partitions ahead of the clock.

## The default store

The core binds the contract to `Cbox\Cms\Core\ReceiptStore\Adapter\PostgresReceiptStore` in `cbox-cms.contracts`, as a singleton. It runs on the default connection, the one the command kernel opens its transaction on, and keeps the receipts in `receipts`, with the position as an `xid8` column, and `receipt_projections`. Its first statement takes the lock on the changeset and reads `pg_current_xact_id()`, and a receipt at another position is refused before anything is looked up or written. It writes a receipt and its projections in one statement, so they are stored together or not at all. Both are partitioned by retention class and then by changeset time: Standard receipts per day, dropped a week after the day ends, and Evidence receipts per month, never dropped by the partition manager.

The database holds the app role to what the store does. It may read and insert receipts but not change them, their position included, and on `receipt_projections` it may update only `state` and `acknowledged_at`, so code running as the app role cannot move an Evidence receipt into the Standard partitions, which are dropped after a week, or rewrite a changeset or projection. A trigger refuses every update of an acknowledged projection, for every role, so an acknowledgement stays final whatever code runs.

An application replaces the store with its own class in the `ReceiptStore::class` entry of `contracts` in its own `config/cbox-cms.php`; the entries it leaves out keep their defaults. A replacement passes the shared contract suite first, as shown below.

## Testing code that uses the store

`Cbox\Cms\Testkit\ReceiptStore\FakeReceiptStore` in the testkit of `cboxdk/cms` is the fake. It keeps receipts in memory and reads the time from the `Clock` it is given, so a test moves a `FakeClock` to expire a Standard receipt. Used directly, the fake behaves like a connection without a transaction: `find()` reads, `markProjection()` commits at once, and `store()` throws `TransactionRequired`. Its `session()` hands out connections to the same receipts, with transactions, so a test stores a receipt in a session's transaction at the session's `position()`, which each transaction gets when it first asks, higher than every earlier one, and its `uncover($from, $to)` takes a range of changeset times out of the partitions, so `store()` in the range throws `PartitionMissing`. A store of a changeset that another session's open transaction has stored waits for that transaction, as on Postgres. PHP runs one session at a time, so `whenWaiting($event)` schedules what happens during the wait, such as the other session committing or rolling back; a waiting store runs the events in order until the changeset is free, and throws a `LogicException` when none is left.

The example stores a receipt in a transaction, finds it and marks its projection, then shows a duplicate, a store outside a transaction and the expiry:

<!-- example: examples/Unit/ReceiptStore/FakeReceiptStoreTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Consistency\DuplicateReceipt;
use Cbox\Cms\Contracts\Consistency\ForeignPosition;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Consistency\TransactionRequired;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\StoredReceipt;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\ReceiptStore\FakeReceiptStore;

// Code that takes a ReceiptStore gets the testkit's FakeReceiptStore in its tests. A receipt is
// stored only in the caller's transaction, so a test stores it in a transaction of a session; the
// store itself behaves like a connection without one, where find() reads and markProjection()
// commits at once. A receipt carries the commit position of the transaction that stores it, which
// the session gives. It reads the time from the clock it is given, so moving the clock expires a
// Standard receipt.

it('stores the receipt of a committed changeset, finds it and marks its projection', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-09-01T12:00:00Z'));
    $ids = new FakeIdGenerator(clock: $clock);
    $receipts = new FakeReceiptStore($clock);
    $changesetId = new ChangesetId($ids->next());
    $search = new ProjectionName('search');

    // The command kernel stores the receipt in the command transaction.
    $command = $receipts->session();
    $command->begin();
    $position = $command->position();
    $command->store(new StoredReceipt($changesetId, RetentionClass::Standard, $position, [ProjectionStatus::pending($search)]));
    $command->commit();

    expect($receipts->find($changesetId))
        ->toEqual(new StoredReceipt($changesetId, RetentionClass::Standard, $position, [ProjectionStatus::pending($search)]));

    $indexedAt = $clock->advance(new DateInterval('PT2S'));

    // The receipt is live and lists the projection, so the mark is recorded and returns true.
    expect($receipts->markProjection($changesetId, ProjectionStatus::acknowledged($search, $indexedAt)))->toBeTrue()
        ->and($receipts->find($changesetId)?->projections)->toEqual([ProjectionStatus::acknowledged($search, $indexedAt)]);

    // The receipt does not list the projection, so nothing changes and the mark returns false.
    expect($receipts->markProjection($changesetId, ProjectionStatus::acknowledged(new ProjectionName('acme.feed'), $indexedAt)))->toBeFalse();
});

it('refuses a second receipt for a changeset and a store outside a transaction, and forgets a Standard receipt after seven days', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-09-01T12:00:00Z'));
    $ids = new FakeIdGenerator(clock: $clock);
    $receipts = new FakeReceiptStore($clock);
    $changesetId = new ChangesetId($ids->next());
    $edge = new ProjectionName('edge');
    $command = $receipts->session();
    $command->begin();
    $command->store(new StoredReceipt($changesetId, RetentionClass::Standard, $command->position(), [ProjectionStatus::pending($edge)]));
    $command->commit();

    // A second receipt for the changeset is refused, and so are a receipt at another transaction's
    // position and a store outside a transaction.
    $command->begin();
    expect(fn () => $command->store(new StoredReceipt($changesetId, RetentionClass::Evidence, $command->position())))
        ->toThrow(DuplicateReceipt::class)
        ->and(fn () => $command->store(new StoredReceipt(new ChangesetId($ids->next()), RetentionClass::Standard, new CommitPosition('1'))))
        ->toThrow(ForeignPosition::class);
    $command->rollBack();

    expect(fn () => $receipts->store(new StoredReceipt(new ChangesetId($ids->next()), RetentionClass::Standard, new CommitPosition('1'))))
        ->toThrow(TransactionRequired::class);

    // Expiry is logical: the receipt is live up to RetentionClass::expiresAt() and gone once the
    // clock is later. A projection that acknowledges after that gets false, which is not an error.
    $clock->set(RetentionClass::Standard->expiresAt($changesetId) ?? throw new LogicException('A Standard receipt expires.'));
    expect($receipts->find($changesetId))->not->toBeNull();

    $clock->advance(new DateInterval('PT1S'));
    expect($receipts->find($changesetId))->toBeNull()
        ->and($receipts->markProjection($changesetId, ProjectionStatus::acknowledged($edge, $clock->now())))->toBeFalse();
});
```

## Testing a replacement

<!-- extension-point: Cbox\Cms\Testkit\ReceiptStore\ReceiptStoreContract -->
<!-- extension-point: Cbox\Cms\Testkit\ReceiptStore\ReceiptStoreHarness -->
<!-- extension-point: Cbox\Cms\Testkit\ReceiptStore\ReceiptStoreSession -->
<!-- extension-point: Cbox\Cms\Testkit\Sessions\TransactionalSession -->

Every implementation of `ReceiptStore`, the fake and the Postgres store included, runs the shared contract suite `Cbox\Cms\Testkit\ReceiptStore\ReceiptStoreContract` (GUARDRAILS 2.3). It is a trait for a PHPUnit test class in the package's `tests/Contract` directory. The class implements one method, `receiptStores(Clock $clock): ReceiptStoreHarness`, which returns a harness for a new, empty store whose sessions read the time from `$clock`. The cases move the clock.

The suite needs more than one connection, because it shows that a store runs inside the caller's transaction and that its writes reach others only after commit. Three testkit interfaces describe what it needs:

| Interface | What an implementation provides |
|---|---|
| `Cbox\Cms\Testkit\ReceiptStore\ReceiptStoreHarness` | `session()`, a new connection to the store under test with no transaction open, and `uncover(DateTimeImmutable $from, DateTimeImmutable $to)`, which makes sure that no partition covers the changeset times from `$from` to `$to`, both inclusive. A database harness may remove whole partitions that overlap the range. |
| `Cbox\Cms\Testkit\ReceiptStore\ReceiptStoreSession` | `receipts()`, the store bound to this session's connection, and the transaction control of `TransactionalSession`. |
| `Cbox\Cms\Testkit\Sessions\TransactionalSession` | `begin()`, `commit()`, `rollBack()` and `inTransaction()`: the transaction control the command kernel has in production. `begin()` with a transaction open is an error, because nested transactions and savepoints are forbidden (PRD 4.2). |

`TransactionalSession` is shared with the idempotency store, whose session interface extends it too, so one session for a real database can serve both stores; this page is where it is described. For a fake a session is an object over shared rows; for a database store it is an independent connection to the same database, and the harness drops partitions for `uncover()`.

The example runs the suite against `ArrayReceiptStore`, a replacement kept in PHP arrays so that it needs no services. `ArrayReceiptHarness` is its database, and each `ArrayReceiptSession` is one connection to it. The store reads and writes through the session and never begins or ends a transaction; the session holds the transaction.

<!-- example: examples/Contract/ReceiptStore/ArrayReceiptStoreContractTest.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Contract\ReceiptStore;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Testkit\ReceiptStore\ReceiptStoreContract;
use Cbox\Cms\Testkit\ReceiptStore\ReceiptStoreHarness;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * Runs the shared ReceiptStore suite against ArrayReceiptStore. ArrayReceiptHarness is the
 * database, and each ArrayReceiptSession it hands out is one connection to it, so the suite can
 * open a transaction on one connection and read on another.
 */
final class ArrayReceiptStoreContractTest extends TestCase
{
    use ReceiptStoreContract;

    #[Override]
    protected function receiptStores(Clock $clock): ReceiptStoreHarness
    {
        return new ArrayReceiptHarness($clock);
    }
}
```

The harness:

<!-- example-file: examples/Contract/ReceiptStore/ArrayReceiptHarness.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Contract\ReceiptStore;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\StoredReceipt;
use Cbox\Cms\Contracts\Storage\PartitionMissing;
use Cbox\Cms\Testkit\ReceiptStore\ReceiptStoreHarness;
use Closure;
use DateTimeImmutable;

/**
 * The database behind ArrayReceiptStore: the committed receipts, one row per changeset, the
 * changeset times that no partition covers, and the next transaction's commit position. Every
 * session is a new connection to it.
 */
final class ArrayReceiptHarness implements ReceiptStoreHarness
{
    public const string TABLE = 'receipts';

    /** @var array<string, StoredReceipt> the committed receipts by changeset id */
    private array $committed = [];

    /** @var list<Closure(DateTimeImmutable): bool> the ranges of changeset times no partition covers */
    private array $uncovered = [];

    /** The commit position the next transaction that asks for one gets. */
    private int $nextPosition = 1;

    public function __construct(public readonly Clock $clock) {}

    public function session(): ArrayReceiptSession
    {
        return new ArrayReceiptSession($this);
    }

    public function uncover(DateTimeImmutable $from, DateTimeImmutable $to): void
    {
        $this->uncovered[] = static fn (DateTimeImmutable $at): bool => $at >= $from && $at <= $to;
    }

    /**
     * A new commit position, higher than every one given before, as a database gives a transaction
     * its id when it first needs one.
     */
    public function nextPosition(): CommitPosition
    {
        return new CommitPosition((string) $this->nextPosition++);
    }

    /**
     * The committed row of the changeset.
     */
    public function row(ChangesetId $changesetId): ?StoredReceipt
    {
        return $this->committed[$changesetId->toString()] ?? null;
    }

    /**
     * Commits a row, replacing the one of the same changeset.
     */
    public function put(StoredReceipt $receipt): void
    {
        $this->committed[$receipt->changesetId->toString()] = $receipt;
    }

    /**
     * Puts a new row in its partition, as a database does when it inserts it: a row whose changeset
     * time no partition covers throws PartitionMissing.
     *
     * @throws PartitionMissing
     */
    public function route(StoredReceipt $receipt): void
    {
        $milliseconds = $receipt->changesetId->unixMilliseconds();
        $at = new DateTimeImmutable(sprintf('@%d.%03d', intdiv($milliseconds, 1000), $milliseconds % 1000));

        if (array_any($this->uncovered, static fn (Closure $uncovered): bool => $uncovered($at))) {
            throw PartitionMissing::forTable(self::TABLE);
        }
    }
}
```

The session:

<!-- example-file: examples/Contract/ReceiptStore/ArrayReceiptSession.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Contract\ReceiptStore;

use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\StoredReceipt;
use Cbox\Cms\Contracts\ReceiptStore;
use Cbox\Cms\Testkit\ReceiptStore\ReceiptStoreSession;
use Closure;
use LogicException;
use Throwable;

/**
 * One connection to the ArrayReceiptHarness, with the transaction control the command kernel has
 * in production. The store reads and writes rows through it and never begins or ends a
 * transaction.
 *
 * Without a transaction a write commits at once. Inside one, a write is checked against the row
 * this connection sees when it is made, and kept. The connection replays its writes over the
 * committed row on every read, and once more at commit, so other connections see them only then.
 * A commit whose replay throws ends the transaction and applies nothing. A write that throws
 * inside a transaction fails it, as a failed statement does on Postgres: until rollBack() the
 * connection takes nothing else. A transaction gets its commit position the first time it asks.
 */
final class ArrayReceiptSession implements ReceiptStoreSession
{
    /** @var array<string, list<Closure(?StoredReceipt): ?StoredReceipt>>|null the writes of the open transaction by changeset id */
    private ?array $writes = null;

    private bool $failed = false;

    private ?CommitPosition $position = null;

    public function __construct(private readonly ArrayReceiptHarness $database) {}

    public function receipts(): ReceiptStore
    {
        return new ArrayReceiptStore($this, $this->database->clock);
    }

    public function begin(): void
    {
        if ($this->writes !== null) {
            throw new LogicException('A transaction is open already; nested transactions and savepoints are forbidden.');
        }

        $this->writes = [];
        $this->position = null;
    }

    public function commit(): void
    {
        $writes = $this->writes ?? throw new LogicException('No transaction is open.');
        $this->refuseWhenFailed();
        $this->writes = null;
        $this->position = null;
        $rows = [];

        foreach ($writes as $key => $rowWrites) {
            $rows[] = $this->replay($this->database->row(ChangesetId::fromString($key)), $rowWrites);
        }

        foreach ($rows as $row) {
            if ($row instanceof StoredReceipt) {
                $this->database->put($row);
            }
        }
    }

    public function rollBack(): void
    {
        if ($this->writes === null) {
            throw new LogicException('No transaction is open.');
        }

        $this->writes = null;
        $this->failed = false;
        $this->position = null;
    }

    public function inTransaction(): bool
    {
        return $this->writes !== null;
    }

    public function position(): CommitPosition
    {
        if ($this->writes === null) {
            throw new LogicException('No transaction is open, so there is no commit position.');
        }

        return $this->position ??= $this->database->nextPosition();
    }

    /**
     * The row of the changeset as this connection sees it: committed, with its own writes.
     */
    public function row(ChangesetId $changesetId): ?StoredReceipt
    {
        $this->refuseWhenFailed();

        return $this->replay($this->database->row($changesetId), $this->writes[$changesetId->toString()] ?? []);
    }

    /**
     * Writes the row of the changeset: $write turns the row into the new row, or throws.
     *
     * @param  Closure(?StoredReceipt): ?StoredReceipt  $write
     */
    public function write(ChangesetId $changesetId, Closure $write): void
    {
        $row = $this->row($changesetId);

        try {
            $written = $write($row);

            if (! $row instanceof StoredReceipt && $written instanceof StoredReceipt) {
                $this->database->route($written);
            }
        } catch (Throwable $error) {
            $this->failed = $this->writes !== null;

            throw $error;
        }

        if ($this->writes !== null) {
            $this->writes[$changesetId->toString()][] = $write;
        } elseif ($written instanceof StoredReceipt) {
            $this->database->put($written);
        }
    }

    /**
     * @param  list<Closure(?StoredReceipt): ?StoredReceipt>  $writes
     */
    private function replay(?StoredReceipt $row, array $writes): ?StoredReceipt
    {
        foreach ($writes as $write) {
            $row = $write($row);
        }

        return $row;
    }

    private function refuseWhenFailed(): void
    {
        if ($this->failed) {
            throw new LogicException('The transaction failed and takes nothing but a rollback.');
        }
    }
}
```

The store under test:

<!-- example-file: examples/Contract/ReceiptStore/ArrayReceiptStore.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Contract\ReceiptStore;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Consistency\DuplicateReceipt;
use Cbox\Cms\Contracts\Consistency\ForeignPosition;
use Cbox\Cms\Contracts\Consistency\ProjectionState;
use Cbox\Cms\Contracts\Consistency\TransactionRequired;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\StoredReceipt;
use Cbox\Cms\Contracts\ReceiptStore;
use DateTimeImmutable;

/**
 * A replacement receipt store, kept in PHP arrays so that the example needs no services. It runs
 * on the caller's connection, an ArrayReceiptSession, and never begins or ends a transaction. A
 * receipt is stored only inside the caller's transaction, at that transaction's commit position.
 */
final readonly class ArrayReceiptStore implements ReceiptStore
{
    public function __construct(
        private ArrayReceiptSession $connection,
        private Clock $clock,
    ) {}

    public function store(StoredReceipt $receipt): void
    {
        if (! $this->connection->inTransaction()) {
            throw TransactionRequired::forStore();
        }

        $position = $this->connection->position();

        if (! $receipt->position->equals($position)) {
            throw ForeignPosition::forReceipt($receipt->changesetId, $receipt->position, $position);
        }

        // An expired receipt still holds its changeset until its partition is dropped.
        $this->connection->write(
            $receipt->changesetId,
            static fn (?StoredReceipt $row): StoredReceipt => $row instanceof StoredReceipt
                ? throw DuplicateReceipt::forChangeset($receipt->changesetId)
                : $receipt,
        );
    }

    public function find(ChangesetId $changesetId): ?StoredReceipt
    {
        return self::live($this->connection->row($changesetId), $this->clock->now());
    }

    public function markProjection(ChangesetId $changesetId, ProjectionStatus $status): bool
    {
        $now = $this->clock->now();

        if (! self::marked($this->connection->row($changesetId), $status, $now) instanceof StoredReceipt) {
            return false;
        }

        $this->connection->write(
            $changesetId,
            static fn (?StoredReceipt $row): ?StoredReceipt => self::marked($row, $status, $now) ?? $row,
        );

        return true;
    }

    /**
     * The receipt, or null when there is none or the clock is past its expiry.
     */
    private static function live(?StoredReceipt $receipt, DateTimeImmutable $now): ?StoredReceipt
    {
        if (! $receipt instanceof StoredReceipt) {
            return null;
        }

        $expiresAt = $receipt->retentionClass->expiresAt($receipt->changesetId);

        return ! $expiresAt instanceof DateTimeImmutable || $now <= $expiresAt ? $receipt : null;
    }

    /**
     * The receipt with the projection marked, or null when the receipt is not live or does not
     * list the projection. An acknowledgement is final, so an acknowledged projection stays as it
     * is.
     */
    private static function marked(?StoredReceipt $row, ProjectionStatus $status, DateTimeImmutable $now): ?StoredReceipt
    {
        $receipt = self::live($row, $now);

        if (! $receipt instanceof StoredReceipt) {
            return null;
        }

        $projections = [];
        $listed = false;

        foreach ($receipt->projections as $current) {
            if ($current->projection->equals($status->projection)) {
                $listed = true;
                $current = $current->state === ProjectionState::Acknowledged ? $current : $status;
            }

            $projections[] = $current;
        }

        return $listed ? new StoredReceipt($receipt->changesetId, $receipt->retentionClass, $receipt->position, $projections) : null;
    }
}
```

Both examples run with `vendor/bin/pest examples --filter=ReceiptStore`, and in gate 5 with their suites, Unit and Contract. The Postgres store runs the same suite in `packages/core/tests/Postgres/PostgresReceiptStoreContractTest.php`, with sessions on independent connections to the test database.
