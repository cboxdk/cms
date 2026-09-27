<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\ReceiptStore;

use Cbox\Cms\Contracts\Consistency\DuplicateReceipt;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Consistency\ProjectionState;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\StoredReceipt;
use Cbox\Cms\Contracts\Storage\PartitionMissing;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\ReceiptStore\FakeReceiptStore;
use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;

/*
 * The fake's own behaviour beyond the shared suite: its sessions refuse nesting and stray
 * commits, replay their writes in commit order, and a failed commit applies nothing. uncover()
 * takes exactly its range out of the partitions, and PartitionMissing fails a transaction.
 */

function fakeReceipt(FakeIdGenerator $ids): StoredReceipt
{
    return new StoredReceipt(new ChangesetId($ids->next()), RetentionClass::Standard, [
        ProjectionStatus::pending(new ProjectionName('fragments')),
        ProjectionStatus::pending(new ProjectionName('search')),
    ]);
}

it('works without arguments, on a FakeClock of its own', function (): void {
    $store = new FakeReceiptStore;
    $receipt = fakeReceipt(new FakeIdGenerator);

    $store->store($receipt);

    expect($store->find($receipt->changesetId))->toEqual($receipt)
        ->and($store->clock())->toBeInstanceOf(FakeClock::class);
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
    $receipt = fakeReceipt(new FakeIdGenerator(clock: $clock));
    $changesetId = $receipt->changesetId;
    $store->store($receipt);
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

it('fails the second commit of the same changeset and applies none of its writes', function (): void {
    $clock = new FakeClock;
    $store = new FakeReceiptStore($clock);
    $ids = new FakeIdGenerator(clock: $clock);
    $receipt = fakeReceipt($ids);
    $other = fakeReceipt($ids);
    $first = $store->session();
    $second = $store->session();

    $first->begin();
    $second->begin();
    $second->store($other);
    $second->store($receipt);
    $first->store($receipt);
    $first->commit();

    expect(fn () => $second->commit())->toThrow(DuplicateReceipt::class)
        ->and($second->inTransaction())->toBeFalse()
        ->and($store->find($other->changesetId))->toBeNull()
        ->and($store->find($receipt->changesetId))->toEqual($receipt);
});

it('fails the second commit of a changeset stored in the other retention class too', function (RetentionClass $firstClass, RetentionClass $secondClass): void {
    $clock = new FakeClock;
    $store = new FakeReceiptStore($clock);
    $receipt = new StoredReceipt(new ChangesetId(new FakeIdGenerator(clock: $clock)->next()), $firstClass);
    $first = $store->session();
    $second = $store->session();

    $first->begin();
    $second->begin();
    $first->store($receipt);
    $second->store(new StoredReceipt($receipt->changesetId, $secondClass));
    $first->commit();

    expect(fn () => $second->commit())->toThrow(DuplicateReceipt::class)
        ->and($second->inTransaction())->toBeFalse()
        ->and($store->find($receipt->changesetId))->toEqual($receipt);
})->with([
    'standard, then evidence' => [RetentionClass::Standard, RetentionClass::Evidence],
    'evidence, then standard' => [RetentionClass::Evidence, RetentionClass::Standard],
]);

it('checks a write when it is made, inside a transaction too, and keeps the transaction open', function (): void {
    $store = new FakeReceiptStore;
    $receipt = fakeReceipt(new FakeIdGenerator);
    $session = $store->session();

    $session->begin();
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
    $receipt = fakeReceipt($ids);
    $store->store($receipt);
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

    $before = fakeReceipt($ids);
    $store->store($before);

    foreach ([1, 2] as $millisecond) {
        $clock->set(new DateTimeImmutable(sprintf('2031-05-01T10:00:00.00%dZ', $millisecond)));
        expect(fn () => $store->store(fakeReceipt($ids)))->toThrow(PartitionMissing::class, 'No partition of table "receipts" covers the row');
    }

    $clock->set($clock->now()->modify('+1 millisecond'));
    $after = fakeReceipt($ids);
    $store->store($after);

    expect($store->find($before->changesetId))->toEqual($before)
        ->and($store->find($after->changesetId))->toEqual($after);
});

it('refuses every call and a commit after PartitionMissing until the transaction rolls back', function (): void {
    $clock = new FakeClock;
    $store = new FakeReceiptStore($clock);
    $store->uncover($clock->now()->setTime(0, 0), $clock->now()->modify('+1 day'));
    $receipt = fakeReceipt(new FakeIdGenerator(clock: $clock));
    $session = $store->session();
    $session->begin();

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
