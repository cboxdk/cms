<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\ReceiptStore;

use Cbox\Cms\Contracts\Consistency\DuplicateReceipt;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Consistency\ProjectionState;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Consistency\UnstorableReceipt;
use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\ReceiptStore\FakeReceiptStore;
use DateInterval;
use LogicException;

/*
 * The fake's own behaviour beyond the shared suite: its sessions refuse nesting and stray
 * commits, replay their writes in commit order, and a failed commit applies nothing.
 */

function fakeReceipt(FakeIdGenerator $ids): Receipt
{
    return Receipt::committed(new ChangesetId($ids->next()), WaitLevel::Commit, RetentionClass::Standard, [
        ProjectionStatus::pending(new ProjectionName('fragments')),
        ProjectionStatus::pending(new ProjectionName('search')),
    ]);
}

function fakeChangeset(Receipt $receipt): ChangesetId
{
    return $receipt->changesetId ?? throw new LogicException('The fixture receipt has no changeset.');
}

it('works without arguments, on a FakeClock of its own', function (): void {
    $store = new FakeReceiptStore;
    $receipt = fakeReceipt(new FakeIdGenerator);

    $store->store($receipt);

    expect($store->find(fakeChangeset($receipt)))->toEqual($receipt)
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
    $changesetId = fakeChangeset($receipt);
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
        ->and($store->find(fakeChangeset($other)))->toBeNull()
        ->and($store->find(fakeChangeset($receipt)))->toEqual($receipt);
});

it('checks a write when it is made, inside a transaction too, and keeps the transaction open', function (): void {
    $store = new FakeReceiptStore;
    $receipt = fakeReceipt(new FakeIdGenerator);
    $session = $store->session();

    $session->begin();
    $session->store($receipt);

    expect(fn () => $session->store($receipt))->toThrow(DuplicateReceipt::class)
        ->and(fn () => $session->store(Receipt::dryRun(WaitLevel::Commit, RetentionClass::Standard)))->toThrow(UnstorableReceipt::class)
        ->and($session->inTransaction())->toBeTrue();

    $session->commit();

    expect($store->find(fakeChangeset($receipt)))->toEqual($receipt);
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
        ->and($session->markProjection(fakeChangeset($receipt), ProjectionStatus::pending(new ProjectionName('edge'))))->toBeFalse();

    $session->commit();

    expect($store->find(fakeChangeset($receipt)))->toEqual($receipt);
});
