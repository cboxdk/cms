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
    $stored = new StoredReceipt($changesetId, RetentionClass::Standard, $receipts->position());
    $receipts->store($stored);
    $idempotency->complete($claim instanceof Fresh ? $claim->token : throw new LogicException('The first call is not fresh.'), $changesetId);
    $receipts->commit();
    $idempotency->commit();

    // A retry with the same key and content: Replay, with the id of the changeset the first call
    // committed. The record holds only the id; the receipt comes from the ReceiptStore.
    $idempotency->begin();
    $replay = $idempotency->claim($scope, $key, $hash, WaitBudget::milliseconds(2000));
    expect($replay)->toEqual(new Replay($changesetId))
        ->and($receipts->find($replay instanceof Replay ? $replay->changesetId : throw new LogicException('The retry is not a replay.')))
        ->toEqual($stored);
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
    $receipts->store(new StoredReceipt($changesetId, RetentionClass::Standard, $receipts->position()));
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
    $stored = new StoredReceipt($changesetId, RetentionClass::Standard, $firstReceipts->position());
    $firstReceipts->store($stored);
    $first->complete($claim instanceof Fresh ? $claim->token : throw new LogicException('The first call is not fresh.'), $changesetId);

    // A retry that may not wait is InFlight; it holds nothing, and its transaction stays usable.
    $retry = $store->session();
    $retry->begin();
    expect($retry->claim($scope, $key, $hash, WaitBudget::none()))->toBeInstanceOf(InFlight::class);

    // The fake runs one session at a time. whenWaiting() says what happens while a claim waits:
    // after 40 ms the first call commits, so a retry with a budget of 2000 ms gets its changeset.
    $store->whenWaiting(40, static function () use ($first, $firstReceipts): void {
        $firstReceipts->commit();
        $first->commit();
    });

    expect($retry->claim($scope, $key, $hash, WaitBudget::milliseconds(2000)))->toEqual(new Replay($changesetId))
        ->and($receipts->find($changesetId))->toEqual($stored);
});
