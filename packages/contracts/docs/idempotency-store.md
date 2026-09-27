# Idempotency store

<!-- extension-point: Cbox\Cms\Contracts\IdempotencyStore -->
<!-- extension-point: Cbox\Cms\Testkit\Idempotency\IdempotencyStoreHarness -->
<!-- extension-point: Cbox\Cms\Testkit\Idempotency\IdempotencyStoreSession -->
<!-- extension-point: Cbox\Cms\Testkit\Idempotency\IdempotencyStoreContract -->

A command can carry an idempotency key (PRD 6.1). The key belongs to a scope, the actor or source plus the command type, and is kept for 7 days together with a hash of the command's content. A repeated call with the same key and the same content returns the original result instead of running again. The same key with other content is refused with `idempotency_conflict`. A call that arrives while the first one is still running waits for it.

`Cbox\Cms\Contracts\IdempotencyStore` is the contract behind this. The command kernel calls it inside the command transaction; an application or addon replaces it, decorates it or runs the testkit's fake in its own tests. This page covers the contract, the default store on Postgres, the fake, and how a replacement proves that it keeps the contract.

## The contract

The store has two methods, `claim()` and `complete()`. There is no `release()`: the end of the caller's transaction releases a claim.

Both methods run on the caller's connection, inside the caller's open transaction: the command transaction that the kernel opens (PRD 6.2 phase 7). A store never begins, commits or rolls back a transaction and never uses a savepoint (PRD 4.2, GUARDRAILS 4.1). Without an open transaction both throw `InvalidClaim`. The store on Postgres also needs READ COMMITTED: at another isolation level `claim()` throws `InvalidClaim` and takes no lock, because a snapshot taken before the wait would hide a record committed while the claim waited.

### claim()

`claim(IdempotencyScope $scope, IdempotencyKey $key, ContentHash $hash, WaitBudget $waitBudget)` takes a claim on the scope and key. When another open transaction holds the claim, it waits for that transaction to end, at most the `WaitBudget`. The budget is 0 to 5000 ms (`WaitBudget::MAX_MILLISECONDS`) of real elapsed time that the store measures, never time read from the `Clock`; `WaitBudget::none()` does not wait. With the claim held, it looks up the completed record for the key and returns one of four results:

| Result | When | What the caller does |
|---|---|---|
| `Fresh` | no live record for the key | runs the command and calls `complete()` with `$result->token` when it commits a changeset |
| `Replay` | a record with the same content hash | returns the receipt of `$result->changesetId` instead of running the command |
| `Conflict` | a record with another content hash | rejects the command with `Conflict::CODE`, `idempotency_conflict` |
| `InFlight` | another transaction held the claim for the whole budget | tells the caller to retry; nothing is held, and the transaction stays usable |

Every result but `InFlight` holds the claim until the caller's transaction ends, by commit or rollback. Two transactions never hold the claim on one key at once.

### complete()

`complete(ClaimToken $token, ChangesetId $changesetId)` records that the `Fresh` claim with this token committed the changeset. It writes the record in the caller's transaction, so the record commits with the changeset or not at all: after a rollback, or a commit without `complete()` such as a rejected command, the key is fresh again. The holding transaction sees its own record before commit; other transactions see it only after.

`complete()` throws `InvalidClaim` for a token that is not from a `Fresh` claim held by this transaction, or one that was already completed. A store on a database keeps the records in a table partitioned by the record's date, the later of the `Clock`'s time and the changeset's time. When no partition covers that date, `complete()` throws `PartitionMissing` (`partition_missing`) and records nothing, and the caller rolls back.

### Replay by reference

A record holds the changeset id, not a copy of the receipt. The caller resolves a `Replay` to its receipt with `ReceiptStore::find()`, so the projection statuses it returns are current. Expiry follows the receipt: a record is live until `RetentionClass::Standard->expiresAt()` for its changeset, the time in the `ChangesetId` plus 7 days, so a `Replay` never names a changeset whose receipt has expired. After that the key is `Fresh` again. The receipt store and the sessions both stores share, `TransactionalSession`, are on the [receipt store](receipt-store.md) page.

## The default: PostgresIdempotencyStore

`cms.contracts` binds `IdempotencyStore` to `Cbox\Cms\Core\IdempotencyStore\Adapter\PostgresIdempotencyStore` (`packages/core/config/cms.php`). It runs on the default connection as the app role, inside the caller's transaction. Its records live in `idempotency_keys`, partitioned per day and kept for 7 days by `cms:partitions:maintain`. Postgres cannot keep a key unique across daily partitions, so the claim is a transaction-scoped advisory lock on a hash of the scope and key, polled with `pg_try_advisory_xact_lock` until it is granted or the budget has passed; it never blocks inside Postgres, where a lock timeout would abort the caller's transaction. The core runs the shared suite against it in `packages/core/tests/Postgres/PostgresIdempotencyStoreContractTest.php`.

An application replaces the store by overriding that one entry of `cms.contracts` in its `config/cms.php`, `IdempotencyStore::class => CountingIdempotencyStore::class` for the example below. A decorator of the default, like that one, also needs to be given the store it wraps, with a contextual binding in the application's service provider: `$this->app->when(CountingIdempotencyStore::class)->needs(IdempotencyStore::class)->give(PostgresIdempotencyStore::class)`.

## The fake: FakeIdempotencyStore

`Cbox\Cms\Testkit\Idempotency\FakeIdempotencyStore` keeps the records in memory and reads the time from the `Clock` it is given, a `FakeClock` by default, so a test moves the clock past a record's expiry. The contract runs only inside a transaction, so the fake of the contract is the session: `session()` gives a `FakeIdempotencySession` with `begin()`, `commit()` and `rollBack()`, and each session is one connection to the same records. `uncover($from, $to)` takes record dates out of the partitions, so a test can meet `PartitionMissing`.

PHP runs one session at a time, so a claim that another session holds cannot end while a claim waits. `whenWaiting($afterMilliseconds, $event)` models the wait: it schedules what happens once a contested claim has waited that long, such as the holder committing. A contested claim runs the events due within its budget, in order, until the claim is free, and is `InFlight` when none is left. No real time passes, and the clock does not move.

The receipt store has a fake of the same shape, `FakeReceiptStore`. This example runs the life of a key through a command on both fakes: `Fresh`, the receipt stored and the claim completed in one transaction; a `Replay` with the changeset id that `find()` resolves; a `Conflict` for other content; a `Fresh` key again after a rollback; and a retry that waits for a call in flight. It is in the `Unit` suite:

<!-- example: examples/Unit/IdempotencyStore/CommandFlowTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Idempotency\Conflict;
use Cbox\Cms\Contracts\Idempotency\ContentHash;
use Cbox\Cms\Contracts\Idempotency\Fresh;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Idempotency\IdempotencyScope;
use Cbox\Cms\Contracts\Idempotency\InFlight;
use Cbox\Cms\Contracts\Idempotency\Replay;
use Cbox\Cms\Contracts\Idempotency\WaitBudget;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\PrincipalId;
use Cbox\Cms\Contracts\Receipts\StoredReceipt;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Idempotency\FakeIdempotencyStore;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\ReceiptStore\FakeReceiptStore;

// A command with an idempotency key, step by step, on the testkit's fakes. In the application the
// command kernel opens one transaction, and both stores run inside it on the same connection. Each
// fake hands out sessions of its own, so here a call begins and ends one session of each together.

it('runs a command for a fresh key, replays it for the same content and refuses other content', function (): void {
    $clock = new FakeClock;
    $ids = new FakeIdGenerator(clock: $clock);
    $idempotency = new FakeIdempotencyStore($clock)->session();
    $receipts = new FakeReceiptStore($clock)->session();
    $scope = IdempotencyScope::forActor(new PrincipalId('user:7'), new CommandName('entry.release'));
    $key = new IdempotencyKey('0193a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b');
    $hash = ContentHash::of('{"entry":"42"}');

    // The first call: the claim is Fresh. The command commits a changeset, stores its receipt and
    // completes the claim with the changeset id, all in the command transaction.
    $idempotency->begin();
    $receipts->begin();
    $claim = $idempotency->claim($scope, $key, $hash, WaitBudget::milliseconds(2000));
    expect($claim)->toBeInstanceOf(Fresh::class);
    $changesetId = new ChangesetId($ids->next());
    $receipts->store(new StoredReceipt($changesetId, RetentionClass::Standard));
    $idempotency->complete($claim instanceof Fresh ? $claim->token : throw new LogicException('The first call is not fresh.'), $changesetId);
    $receipts->commit();
    $idempotency->commit();

    // A retry with the same key and content: Replay, with the id of the changeset the first call
    // committed. The record holds only the id; the receipt comes from the ReceiptStore.
    $idempotency->begin();
    $replay = $idempotency->claim($scope, $key, $hash, WaitBudget::milliseconds(2000));
    expect($replay)->toEqual(new Replay($changesetId))
        ->and($receipts->find($replay instanceof Replay ? $replay->changesetId : throw new LogicException('The retry is not a replay.')))
        ->toEqual(new StoredReceipt($changesetId, RetentionClass::Standard));
    $idempotency->rollBack();

    // The same key with other content: Conflict, which the command reports as idempotency_conflict.
    $idempotency->begin();
    expect($idempotency->claim($scope, $key, ContentHash::of('{"entry":"43"}'), WaitBudget::milliseconds(2000)))
        ->toEqual(new Conflict($scope, $key))
        ->and(Conflict::CODE)->toBe('idempotency_conflict');
    $idempotency->rollBack();
});

it('leaves the key fresh when the command transaction rolls back', function (): void {
    $clock = new FakeClock;
    $ids = new FakeIdGenerator(clock: $clock);
    $idempotency = new FakeIdempotencyStore($clock)->session();
    $receipts = new FakeReceiptStore($clock)->session();
    $scope = IdempotencyScope::forActor(new PrincipalId('user:7'), new CommandName('entry.release'));
    $key = new IdempotencyKey('0193a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b');
    $hash = ContentHash::of('{"entry":"42"}');

    // The command completes its claim, and then an invariant rejects it: the transaction rolls
    // back, and the record, the receipt and the claim go with it. There is no release().
    $idempotency->begin();
    $receipts->begin();
    $claim = $idempotency->claim($scope, $key, $hash, WaitBudget::milliseconds(2000));
    $changesetId = new ChangesetId($ids->next());
    $receipts->store(new StoredReceipt($changesetId, RetentionClass::Standard));
    $idempotency->complete($claim instanceof Fresh ? $claim->token : throw new LogicException('The first call is not fresh.'), $changesetId);
    $receipts->rollBack();
    $idempotency->rollBack();

    // The next call with the key starts over.
    $idempotency->begin();
    expect($idempotency->claim($scope, $key, $hash, WaitBudget::milliseconds(2000)))->toBeInstanceOf(Fresh::class)
        ->and($receipts->find($changesetId))->toBeNull();
});

it('lets a retry wait for the call in flight within its wait budget, and then replays that call', function (): void {
    $clock = new FakeClock;
    $ids = new FakeIdGenerator(clock: $clock);
    $store = new FakeIdempotencyStore($clock);
    $receipts = new FakeReceiptStore($clock);
    $scope = IdempotencyScope::forActor(new PrincipalId('user:7'), new CommandName('entry.release'));
    $key = new IdempotencyKey('0193a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b');
    $hash = ContentHash::of('{"entry":"42"}');

    // The first call has completed its claim but not committed yet.
    $first = $store->session();
    $firstReceipts = $receipts->session();
    $first->begin();
    $firstReceipts->begin();
    $claim = $first->claim($scope, $key, $hash, WaitBudget::milliseconds(2000));
    $changesetId = new ChangesetId($ids->next());
    $firstReceipts->store(new StoredReceipt($changesetId, RetentionClass::Standard));
    $first->complete($claim instanceof Fresh ? $claim->token : throw new LogicException('The first call is not fresh.'), $changesetId);

    // A retry that may not wait is InFlight; it holds nothing, and its transaction stays usable.
    $retry = $store->session();
    $retry->begin();
    expect($retry->claim($scope, $key, $hash, WaitBudget::none()))->toBeInstanceOf(InFlight::class);

    // No real time passes in the fake. whenWaiting() says what happens while a claim waits: after
    // 40 ms the first call commits, so a retry with a budget of 2000 ms gets its changeset.
    $store->whenWaiting(40, static function () use ($first, $firstReceipts): void {
        $firstReceipts->commit();
        $first->commit();
    });

    expect($retry->claim($scope, $key, $hash, WaitBudget::milliseconds(2000)))->toEqual(new Replay($changesetId))
        ->and($receipts->find($changesetId))->toEqual(new StoredReceipt($changesetId, RetentionClass::Standard));
});
```

## Running the shared suite against a replacement

A replacement needs only `cboxdk/cms-contracts`, and `cboxdk/cms-testkit` for its tests (GUARDRAILS 2.6). Every implementation runs the testkit's shared suite, the trait `Cbox\Cms\Testkit\Idempotency\IdempotencyStoreContract`, in a PHPUnit test class in its `tests/Contract` directory. The trait has one abstract method, `idempotencyStores(Clock $clock): IdempotencyStoreHarness`, which returns a harness for a new, empty store whose sessions read the time from `$clock`. The cases move that clock.

The suite needs more than one connection to show that a claim is held, waited for and released when a transaction ends. So it works through two interfaces:

- `IdempotencyStoreHarness` is one store that several sessions reach. `session()` hands out a new session on its own connection, with no transaction open. `uncover($from, $to)` makes sure no partition covers the record dates in the range, so the suite can check that `complete()` throws `PartitionMissing` there. A harness for a decorator passes both calls on to the harness of the store it wraps.
- `IdempotencyStoreSession` is one of those connections. It extends `TransactionalSession`, with `begin()`, `commit()`, `rollBack()` and `inTransaction()`, and adds `idempotency()`, the store bound to that connection. For a store on a database each session is an independent connection to the same database, as the core's `PostgresIdempotencySessions` does; one session can then serve the receipt store as well.

The example decorates a store and counts the claims by result. The decorator passes every call through:

<!-- example-file: examples/Contract/IdempotencyStore/CountingIdempotencyStore.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Contract\IdempotencyStore;

use Cbox\Cms\Contracts\Idempotency\ClaimResult;
use Cbox\Cms\Contracts\Idempotency\ClaimToken;
use Cbox\Cms\Contracts\Idempotency\ContentHash;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Idempotency\IdempotencyScope;
use Cbox\Cms\Contracts\Idempotency\WaitBudget;
use Cbox\Cms\Contracts\IdempotencyStore;
use Cbox\Cms\Contracts\Ids\ChangesetId;

/**
 * An IdempotencyStore that an application binds in cms.contracts in place of the default. It
 * passes every call to the store it decorates and counts the claims by result, for a metric.
 */
final class CountingIdempotencyStore implements IdempotencyStore
{
    /** @var array<class-string<ClaimResult>, int> */
    private array $claims = [];

    public function __construct(private readonly IdempotencyStore $store) {}

    public function claim(IdempotencyScope $scope, IdempotencyKey $key, ContentHash $hash, WaitBudget $waitBudget): ClaimResult
    {
        $result = $this->store->claim($scope, $key, $hash, $waitBudget);
        $this->claims[$result::class] = $this->claims($result::class) + 1;

        return $result;
    }

    public function complete(ClaimToken $token, ChangesetId $changesetId): void
    {
        $this->store->complete($token, $changesetId);
    }

    /**
     * How many claims had the result.
     *
     * @param  class-string<ClaimResult>  $result
     */
    public function claims(string $result): int
    {
        return $this->claims[$result] ?? 0;
    }
}
```

Its session wraps a session of the decorated store's harness and puts the decorator over that session's store:

<!-- example-file: examples/Contract/IdempotencyStore/CountingIdempotencySession.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Contract\IdempotencyStore;

use Cbox\Cms\Testkit\Idempotency\IdempotencyStoreSession;

/**
 * One connection for the shared suite: the transaction control of the decorated store's session,
 * and a CountingIdempotencyStore over that session's store.
 */
final readonly class CountingIdempotencySession implements IdempotencyStoreSession
{
    private CountingIdempotencyStore $store;

    public function __construct(private IdempotencyStoreSession $connection)
    {
        $this->store = new CountingIdempotencyStore($connection->idempotency());
    }

    public function idempotency(): CountingIdempotencyStore
    {
        return $this->store;
    }

    public function begin(): void
    {
        $this->connection->begin();
    }

    public function commit(): void
    {
        $this->connection->commit();
    }

    public function rollBack(): void
    {
        $this->connection->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->connection->inTransaction();
    }
}
```

Its harness wraps the decorated store's harness:

<!-- example-file: examples/Contract/IdempotencyStore/CountingIdempotencyStores.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Contract\IdempotencyStore;

use Cbox\Cms\Testkit\Idempotency\IdempotencyStoreHarness;
use DateTimeImmutable;

/**
 * The harness the shared suite runs CountingIdempotencyStore through. It wraps the harness of the
 * decorated store: each session is a CountingIdempotencySession over one of that harness's
 * sessions, and uncover() takes the dates out of the decorated store's partitions.
 */
final readonly class CountingIdempotencyStores implements IdempotencyStoreHarness
{
    public function __construct(private IdempotencyStoreHarness $stores) {}

    public function session(): CountingIdempotencySession
    {
        return new CountingIdempotencySession($this->stores->session());
    }

    public function uncover(DateTimeImmutable $from, DateTimeImmutable $to): void
    {
        $this->stores->uncover($from, $to);
    }
}
```

The test class uses the trait and returns the harness; the decorated store is the fake here, so the suite runs without services. It also tests what the decorator adds. It is in the `Contract` suite:

<!-- example: examples/Contract/IdempotencyStore/CountingIdempotencyStoreContractTest.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Contract\IdempotencyStore;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Idempotency\Conflict;
use Cbox\Cms\Contracts\Idempotency\ContentHash;
use Cbox\Cms\Contracts\Idempotency\Fresh;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Idempotency\IdempotencyScope;
use Cbox\Cms\Contracts\Idempotency\InFlight;
use Cbox\Cms\Contracts\Idempotency\Replay;
use Cbox\Cms\Contracts\Idempotency\WaitBudget;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\PrincipalId;
use Cbox\Cms\Testkit\Idempotency\FakeIdempotencyStore;
use Cbox\Cms\Testkit\Idempotency\IdempotencyStoreContract;
use Cbox\Cms\Testkit\Idempotency\IdempotencyStoreHarness;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The shared IdempotencyStore suite against CountingIdempotencyStore, through the harness
 * CountingIdempotencyStores, which hands out a CountingIdempotencySession for each session of the
 * decorated store's harness. In the application the decorated store is the default,
 * PostgresIdempotencyStore; here it is the testkit's FakeIdempotencyStore, so the suite runs
 * without services. The class also tests what the decorator adds.
 */
final class CountingIdempotencyStoreContractTest extends TestCase
{
    use IdempotencyStoreContract;

    #[Override]
    protected function idempotencyStores(Clock $clock): IdempotencyStoreHarness
    {
        return new CountingIdempotencyStores(new FakeIdempotencyStore($clock));
    }

    #[Test]
    public function the_decorator_counts_every_claim_by_its_result(): void
    {
        $session = new CountingIdempotencyStores(new FakeIdempotencyStore)->session();
        $store = $session->idempotency();
        $scope = IdempotencyScope::forActor(new PrincipalId('user:7'), new CommandName('entry.release'));
        $key = new IdempotencyKey('0193a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b');
        $hash = ContentHash::of('{"entry":"42"}');

        $session->begin();
        $claim = $store->claim($scope, $key, $hash, WaitBudget::none());
        $store->complete($claim instanceof Fresh ? $claim->token : throw new LogicException('The first claim is not fresh.'), new ChangesetId(new FakeIdGenerator()->next()));
        $session->commit();

        $session->begin();
        $store->claim($scope, $key, $hash, WaitBudget::none());
        $store->claim($scope, $key, ContentHash::of('{"entry":"43"}'), WaitBudget::none());
        $session->rollBack();

        self::assertSame(
            [Fresh::class => 1, Replay::class => 1, Conflict::class => 1, InFlight::class => 0],
            [
                Fresh::class => $store->claims(Fresh::class),
                Replay::class => $store->claims(Replay::class),
                Conflict::class => $store->claims(Conflict::class),
                InFlight::class => $store->claims(InFlight::class),
            ],
        );
    }
}
```
