<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Consistency\DuplicateReceipt;
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
// commits at once. It reads the time from the clock it is given, so moving the clock expires a
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
    $command->store(new StoredReceipt($changesetId, RetentionClass::Standard, [ProjectionStatus::pending($search)]));
    $command->commit();

    expect($receipts->find($changesetId))
        ->toEqual(new StoredReceipt($changesetId, RetentionClass::Standard, [ProjectionStatus::pending($search)]));

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
    $command->store(new StoredReceipt($changesetId, RetentionClass::Standard, [ProjectionStatus::pending($edge)]));
    $command->commit();

    // A second receipt for the changeset is refused, and so is a store outside a transaction.
    $command->begin();
    expect(fn () => $command->store(new StoredReceipt($changesetId, RetentionClass::Evidence)))
        ->toThrow(DuplicateReceipt::class);
    $command->rollBack();

    expect(fn () => $receipts->store(new StoredReceipt(new ChangesetId($ids->next()), RetentionClass::Standard)))
        ->toThrow(TransactionRequired::class);

    // Expiry is logical: the receipt is live up to RetentionClass::expiresAt() and gone once the
    // clock is later. A projection that acknowledges after that gets false, which is not an error.
    $clock->set(RetentionClass::Standard->expiresAt($changesetId) ?? throw new LogicException('A Standard receipt expires.'));
    expect($receipts->find($changesetId))->not->toBeNull();

    $clock->advance(new DateInterval('PT1S'));
    expect($receipts->find($changesetId))->toBeNull()
        ->and($receipts->markProjection($changesetId, ProjectionStatus::acknowledged($edge, $clock->now())))->toBeFalse();
});
