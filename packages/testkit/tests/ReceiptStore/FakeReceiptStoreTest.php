<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\ReceiptStore;

use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Consistency\DuplicateReceipt;
use Cbox\Cms\Contracts\Consistency\ForeignPosition;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Consistency\ProjectionState;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Consistency\TransactionRequired;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\StoredReceipt;
use Cbox\Cms\Contracts\Storage\PartitionMissing;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\ReceiptStore\FakeReceiptSession;
use Cbox\Cms\Testkit\ReceiptStore\FakeReceiptStore;
use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;

/*
 * The fake's own behaviour beyond the shared suite: a store without a transaction is refused
 * before it waits, its sessions refuse nesting and stray commits and replay their writes in commit
 * order. A store of a changeset another open transaction
 * stored waits for it through whenWaiting(), as on Postgres, and refuses the duplicate at store().
 * uncover() takes exactly its range out of the partitions, and PartitionMissing fails a transaction.
 */

/**
 * A receipt not stored yet, at position 0; fakeAt() gives it the position of the transaction that
 * stores it.
 */
function fakeReceipt(FakeIdGenerator $ids): StoredReceipt
{
    return new StoredReceipt(new ChangesetId($ids->next()), RetentionClass::Standard, new CommitPosition('0'), [
        ProjectionStatus::pending(new ProjectionName('fragments')),
        ProjectionStatus::pending(new ProjectionName('search')),
    ]);
}

/**
 * The receipt at the commit position of the session's open transaction.
 */
function fakeAt(FakeReceiptSession $session, StoredReceipt $receipt): StoredReceipt
{
    return new StoredReceipt($receipt->changesetId, $receipt->retentionClass, $session->position(), $receipt->projections);
}

/**
 * Stores the receipt in a committed transaction of a new session, as the command kernel does, and
 * returns it as stored.
 */
function commitFakeReceipt(FakeReceiptStore $store, StoredReceipt $receipt): StoredReceipt
{
    $session = $store->session();
    $session->begin();
    $receipt = fakeAt($session, $receipt);
    $session->store($receipt);
    $session->commit();

    return $receipt;
}

it('works without arguments, on a FakeClock of its own', function (): void {
    $store = new FakeReceiptStore;
    $receipt = commitFakeReceipt($store, fakeReceipt(new FakeIdGenerator));

    expect($store->find($receipt->changesetId))->toEqual($receipt)
        ->and($store->clock())->toBeInstanceOf(FakeClock::class);
});

it('refuses a store without a transaction, on the store and on a session, before it waits, locks or stores anything', function (): void {
    $clock = new FakeClock;
    $store = new FakeReceiptStore($clock);
    $ids = new FakeIdGenerator(clock: $clock);
    $held = fakeReceipt($ids);
    $free = fakeReceipt($ids);
    $holder = $store->session();
    $autocommit = $store->session();
    $store->whenWaiting(static function (): void {});

    // The holder's open transaction has the lock on $held: a store that waited for it would run
    // the scheduled event.
    $holder->begin();
    $held = fakeAt($holder, $held);
    $holder->store($held);

    foreach ([$held, $free] as $receipt) {
        expect(fn () => $store->store($receipt))->toThrow(TransactionRequired::class, 'Nothing was stored.')
            ->and(fn () => $autocommit->store($receipt))->toThrow(TransactionRequired::class, 'Nothing was stored.')
            ->and($autocommit->inTransaction())->toBeFalse()
            ->and($store->scheduledWaitEvents())->toBe(1);
    }

    $holder->commit();

    // The refused stores took no lock and stored nothing, so a transaction stores $free at once.
    expect($store->find($free->changesetId))->toBeNull();

    $autocommit->begin();
    $free = fakeAt($autocommit, $free);
    $autocommit->store($free);
    $autocommit->commit();

    expect($store->find($free->changesetId))->toEqual($free)
        ->and($store->find($held->changesetId))->toEqual($held)
        ->and($store->scheduledWaitEvents())->toBe(1);
});

it('gives a transaction its commit position when it first asks, the same for the rest of it, and higher than every earlier one', function (): void {
    $store = new FakeReceiptStore;
    $first = $store->session();
    $second = $store->session();

    expect(fn (): CommitPosition => $first->position())->toThrow(LogicException::class, 'no transaction open');

    $first->begin();
    $second->begin();
    $secondPosition = $second->position();
    $firstPosition = $first->position();

    expect($secondPosition->value)->toBe((string) FakeReceiptStore::FIRST_POSITION)
        ->and($firstPosition->value)->toBe((string) (FakeReceiptStore::FIRST_POSITION + 1))
        ->and($first->position()->equals($firstPosition))->toBeTrue();

    $first->commit();
    $second->rollBack();
    $first->begin();

    expect($secondPosition->isBelow($first->position()) && $firstPosition->isBelow($first->position()))->toBeTrue();
});

it('refuses a receipt at another position before it waits for the changeset, and stores nothing', function (): void {
    $store = new FakeReceiptStore;
    $receipt = fakeReceipt(new FakeIdGenerator);
    $holder = $store->session();
    $session = $store->session();

    $holder->begin();
    $holder->store(fakeAt($holder, $receipt));
    $session->begin();

    expect(fn () => $session->store(fakeAt($holder, $receipt)))->toThrow(ForeignPosition::class, sprintf('The receipt of changeset %s has the position %s, and the transaction storing it is at %s.', $receipt->changesetId->toString(), $holder->position()->value, $session->position()->value))
        ->and($session->inTransaction())->toBeTrue()
        ->and($store->scheduledWaitEvents())->toBe(0);

    $holder->rollBack();
    $session->commit();

    expect($store->find($receipt->changesetId))->toBeNull();
});

it('refuses a nested transaction and names the rule', function (): void {
    $session = new FakeReceiptStore()->session();
    $session->begin();

    expect(fn () => $session->begin())->toThrow(LogicException::class, 'PRD 4.2')
        ->and($session->inTransaction())->toBeTrue();
});

it('refuses a commit or rollback without a transaction', function (): void {
    $session = new FakeReceiptStore()->session();

    expect(fn () => $session->commit())->toThrow(LogicException::class, 'no transaction to commit')
        ->and(fn () => $session->rollBack())->toThrow(LogicException::class, 'no transaction to roll back');
});

it('is its own receipt store for a session', function (): void {
    $session = new FakeReceiptStore()->session();

    expect($session->receipts())->toBe($session);
});

it('keeps both marks when two open transactions mark different projections', function (): void {
    $clock = new FakeClock;
    $store = new FakeReceiptStore($clock);
    $receipt = commitFakeReceipt($store, fakeReceipt(new FakeIdGenerator(clock: $clock)));
    $changesetId = $receipt->changesetId;
    $first = $store->session();
    $second = $store->session();

    $first->begin();
    $second->begin();
    $first->markProjection($changesetId, ProjectionStatus::acknowledged(new ProjectionName('fragments'), $clock->now()));
    $second->markProjection($changesetId, ProjectionStatus::acknowledged(new ProjectionName('search'), $clock->advance(new DateInterval('PT1S'))));
    $first->commit();
    $second->commit();

    $states = array_map(
        static fn (ProjectionStatus $status): ProjectionState => $status->state,
        $store->find($changesetId)->projections ?? [],
    );

    expect($states)->toBe([ProjectionState::Acknowledged, ProjectionState::Acknowledged]);
});

it('refuses a changeset another open transaction stored at store(), once that transaction commits, and commits the rest', function (RetentionClass $firstClass, RetentionClass $secondClass): void {
    $clock = new FakeClock;
    $store = new FakeReceiptStore($clock);
    $ids = new FakeIdGenerator(clock: $clock);
    $first = $store->session();
    $second = $store->session();

    $first->begin();
    $second->begin();
    $receipt = new StoredReceipt(new ChangesetId($ids->next()), $firstClass, $first->position());
    $other = fakeAt($second, fakeReceipt($ids));
    $first->store($receipt);
    $second->store($other);
    $store->whenWaiting($first->commit(...));

    // Postgres makes the second store wait for the first transaction's lock on the changeset and
    // then refuses it; the duplicate comes from store(), never from commit().
    expect(fn () => $second->store(new StoredReceipt($receipt->changesetId, $secondClass, $second->position())))->toThrow(DuplicateReceipt::class, $receipt->changesetId->toString())
        ->and($store->scheduledWaitEvents())->toBe(0)
        ->and($first->inTransaction())->toBeFalse()
        ->and($second->inTransaction())->toBeTrue();

    $second->commit();

    expect($second->inTransaction())->toBeFalse()
        ->and($store->find($receipt->changesetId))->toEqual($receipt)
        ->and($store->find($other->changesetId))->toEqual($other);
})->with([
    'standard, then standard' => [RetentionClass::Standard, RetentionClass::Standard],
    'standard, then evidence' => [RetentionClass::Standard, RetentionClass::Evidence],
    'evidence, then standard' => [RetentionClass::Evidence, RetentionClass::Standard],
]);

it('stores the changeset once the other open transaction that stored it rolls back', function (): void {
    $clock = new FakeClock;
    $store = new FakeReceiptStore($clock);
    $ids = new FakeIdGenerator(clock: $clock);
    $first = $store->session();
    $second = $store->session();

    $first->begin();
    $receipt = fakeAt($first, fakeReceipt($ids));
    $first->store($receipt);
    $second->begin();
    $mine = new StoredReceipt($receipt->changesetId, RetentionClass::Evidence, $second->position());
    $store->whenWaiting($first->rollBack(...));
    $second->store($mine);
    $second->commit();

    expect($store->find($receipt->changesetId))->toEqual($mine)
        ->and($store->scheduledWaitEvents())->toBe(0);
});

it('refuses with a LogicException a store that would wait with no wait event scheduled, and takes nothing', function (): void {
    $clock = new FakeClock;
    $store = new FakeReceiptStore($clock);
    $receipt = fakeReceipt(new FakeIdGenerator(clock: $clock));
    $holder = $store->session();
    $inTransaction = $store->session();
    $other = $store->session();

    $holder->begin();
    $holder->store(fakeAt($holder, $receipt));
    $inTransaction->begin();

    expect(fn () => $inTransaction->store(fakeAt($inTransaction, $receipt)))->toThrow(LogicException::class, sprintf('Another open transaction stored a receipt for changeset %s, so this store waits until that transaction ends, and no wait event is scheduled', $receipt->changesetId->toString()));

    // The refused store took no lock: once the holder rolls back, another transaction's store goes
    // ahead without waiting for the session that was refused.
    $holder->rollBack();
    $other->begin();
    $receipt = fakeAt($other, $receipt);
    $other->store($receipt);
    $other->commit();
    $inTransaction->commit();

    expect($store->find($receipt->changesetId))->toEqual($receipt);
});

it('runs the wait events in the order they were scheduled until the changeset is free, and keeps the rest', function (): void {
    $clock = new FakeClock;
    $store = new FakeReceiptStore($clock);
    $receipt = fakeReceipt(new FakeIdGenerator(clock: $clock));
    $holder = $store->session();
    $waiter = $store->session();
    $ran = [];

    $holder->begin();
    $holder->store(fakeAt($holder, $receipt));
    $store->whenWaiting(static function () use (&$ran): void {
        $ran[] = 'first';
    });
    $store->whenWaiting(static function () use ($holder, &$ran): void {
        $ran[] = 'second';
        $holder->rollBack();
    });
    $store->whenWaiting(static function () use (&$ran): void {
        $ran[] = 'third';
    });

    $waiter->begin();
    $waiter->store(fakeAt($waiter, $receipt));

    expect($ran)->toBe(['first', 'second'])
        ->and($store->scheduledWaitEvents())->toBe(1);
});

it('keeps the changeset locked until the transaction ends, also after its store threw DuplicateReceipt', function (): void {
    $clock = new FakeClock;
    $store = new FakeReceiptStore($clock);
    $receipt = commitFakeReceipt($store, fakeReceipt(new FakeIdGenerator(clock: $clock)));
    $refused = $store->session();
    $third = $store->session();

    $refused->begin();
    $third->begin();

    expect(fn () => $refused->store(fakeAt($refused, $receipt)))->toThrow(DuplicateReceipt::class)
        ->and($refused->inTransaction())->toBeTrue()
        ->and(fn () => $third->store(fakeAt($third, $receipt)))->toThrow(LogicException::class, 'no wait event is scheduled');

    $store->whenWaiting($refused->commit(...));

    expect(fn () => $third->store(fakeAt($third, $receipt)))->toThrow(DuplicateReceipt::class)
        ->and($store->scheduledWaitEvents())->toBe(0);
});

it('never makes a store wait for its own transaction or for another changeset', function (): void {
    $clock = new FakeClock;
    $store = new FakeReceiptStore($clock);
    $ids = new FakeIdGenerator(clock: $clock);
    $first = $store->session();
    $second = $store->session();

    $first->begin();
    $receipt = fakeAt($first, fakeReceipt($ids));
    $first->store($receipt);
    $second->begin();
    $unrelated = fakeAt($second, fakeReceipt($ids));
    $second->store($unrelated);

    expect(fn () => $first->store($receipt))->toThrow(DuplicateReceipt::class);

    $second->commit();
    $first->commit();

    expect($store->find($receipt->changesetId))->toEqual($receipt)
        ->and($store->find($unrelated->changesetId))->toEqual($unrelated);
});

it('checks a write when it is made, inside a transaction too, and keeps the transaction open', function (): void {
    $store = new FakeReceiptStore;
    $session = $store->session();

    $session->begin();
    $receipt = fakeAt($session, fakeReceipt(new FakeIdGenerator));
    $session->store($receipt);

    expect(fn () => $session->store($receipt))->toThrow(DuplicateReceipt::class)
        ->and($session->inTransaction())->toBeTrue();

    $session->commit();

    expect($store->find($receipt->changesetId))->toEqual($receipt);
});

it('reports false for a mark in a transaction that matches nothing and records no write', function (): void {
    $clock = new FakeClock;
    $store = new FakeReceiptStore($clock);
    $ids = new FakeIdGenerator(clock: $clock);
    $receipt = commitFakeReceipt($store, fakeReceipt($ids));
    $session = $store->session();

    $session->begin();

    expect($session->markProjection(new ChangesetId($ids->next()), ProjectionStatus::pending(new ProjectionName('fragments'))))->toBeFalse()
        ->and($session->markProjection($receipt->changesetId, ProjectionStatus::pending(new ProjectionName('edge'))))->toBeFalse();

    $session->commit();

    expect($store->find($receipt->changesetId))->toEqual($receipt);
});

it('refuses an uncovered range that ends before it starts', function (): void {
    $store = new FakeReceiptStore;
    $at = $store->clock()->now();

    expect(fn () => $store->uncover($at, $at->modify('-1 microsecond')))->toThrow(InvalidArgumentException::class, 'ends at or after it starts');
});

it('uncovers exactly the range, both ends inclusive, and names its table', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2031-05-01T10:00:00.000Z'));
    $store = new FakeReceiptStore($clock);
    $store->uncover(new DateTimeImmutable('2031-05-01T10:00:00.001Z'), new DateTimeImmutable('2031-05-01T10:00:00.002Z'));
    $ids = new FakeIdGenerator(clock: $clock);

    $before = commitFakeReceipt($store, fakeReceipt($ids));
    $session = $store->session();

    foreach ([1, 2] as $millisecond) {
        $clock->set(new DateTimeImmutable(sprintf('2031-05-01T10:00:00.00%dZ', $millisecond)));
        $session->begin();
        expect(fn () => $session->store(fakeAt($session, fakeReceipt($ids))))->toThrow(PartitionMissing::class, 'No partition of table "receipts" covers the row');
        $session->rollBack();
    }

    $clock->set($clock->now()->modify('+1 millisecond'));
    $after = commitFakeReceipt($store, fakeReceipt($ids));

    expect($store->find($before->changesetId))->toEqual($before)
        ->and($store->find($after->changesetId))->toEqual($after);
});

it('refuses every call and a commit after PartitionMissing until the transaction rolls back', function (): void {
    $clock = new FakeClock;
    $store = new FakeReceiptStore($clock);
    $store->uncover($clock->now()->setTime(0, 0), $clock->now()->modify('+1 day'));
    $session = $store->session();
    $session->begin();
    $receipt = fakeAt($session, fakeReceipt(new FakeIdGenerator(clock: $clock)));

    expect(fn () => $session->store($receipt))->toThrow(PartitionMissing::class);

    foreach ([
        fn () => $session->store($receipt),
        fn (): ?StoredReceipt => $session->find($receipt->changesetId),
        fn (): bool => $session->markProjection($receipt->changesetId, ProjectionStatus::pending(new ProjectionName('search'))),
        $session->commit(...),
    ] as $call) {
        expect($call)->toThrow(LogicException::class, 'Roll it back.');
    }

    expect($session->inTransaction())->toBeTrue();
    $session->rollBack();

    expect($session->inTransaction())->toBeFalse()
        ->and($session->find($receipt->changesetId))->toBeNull();
});
