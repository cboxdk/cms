<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Idempotency;

use Cbox\Cms\Contracts\Idempotency\ClaimResult;
use Cbox\Cms\Contracts\Idempotency\ContentHash;
use Cbox\Cms\Contracts\Idempotency\Fresh;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Idempotency\IdempotencyScope;
use Cbox\Cms\Contracts\Idempotency\InFlight;
use Cbox\Cms\Contracts\Idempotency\InvalidClaim;
use Cbox\Cms\Contracts\Idempotency\Replay;
use Cbox\Cms\Contracts\Idempotency\WaitBudget;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\PrincipalId;
use Cbox\Cms\Contracts\Storage\PartitionMissing;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Idempotency\FakeIdempotencySession;
use Cbox\Cms\Testkit\Idempotency\FakeIdempotencyStore;
use Cbox\Cms\Testkit\Idempotency\HolderEnd;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;

/*
 * The fake's own behaviour beyond the shared suite: its sessions refuse nesting and stray
 * commits, and whenWaiting() models what happens while a contested claim waits within its budget,
 * in real time. What a waiting claim gets when its holder commits or rolls back is in the shared
 * suite, through holdWhileWaiting().
 * uncover() takes exactly its range out of the partitions, and PartitionMissing fails a
 * transaction.
 */

function fakeClaim(FakeIdempotencySession $session, int $budget = 0): ClaimResult
{
    return $session->claim(
        IdempotencyScope::forActor(new PrincipalId('user:7'), new CommandName('entry.release')),
        new IdempotencyKey('retry-me'),
        ContentHash::of('{"value":"A"}'),
        WaitBudget::milliseconds($budget),
    );
}

function fakeFreshClaim(FakeIdempotencySession $session): Fresh
{
    $result = fakeClaim($session);

    return $result instanceof Fresh ? $result : throw new LogicException('The fixture claim is not fresh.');
}

it('works without arguments, on a FakeClock of its own', function (): void {
    $store = new FakeIdempotencyStore;
    $session = $store->session();
    $session->begin();

    expect(fakeClaim($session))->toBeInstanceOf(Fresh::class)
        ->and($store->clock())->toBeInstanceOf(FakeClock::class)
        ->and($store->scheduledWaitEvents())->toBe(0);
});

it('refuses a nested transaction and names the rule', function (): void {
    $session = new FakeIdempotencyStore()->session();
    $session->begin();

    expect(fn () => $session->begin())->toThrow(LogicException::class, 'PRD 4.2')
        ->and($session->inTransaction())->toBeTrue();
});

it('refuses a commit or rollback without a transaction', function (): void {
    $session = new FakeIdempotencyStore()->session();

    expect(fn () => $session->commit())->toThrow(LogicException::class, 'no transaction to commit')
        ->and(fn () => $session->rollBack())->toThrow(LogicException::class, 'no transaction to roll back');
});

it('is its own idempotency store for a session', function (): void {
    $session = new FakeIdempotencyStore()->session();

    expect($session->idempotency())->toBe($session);
});

it('refuses a claim outside a transaction and says why', function (): void {
    $session = new FakeIdempotencyStore()->session();

    expect(fn (): ClaimResult => fakeClaim($session))->toThrow(InvalidClaim::class, 'IdempotencyStore::claim() runs inside the caller\'s command transaction');
});

it('is in flight when the holder ends after the budget, and keeps that event for a later wait', function (): void {
    $store = new FakeIdempotencyStore;
    $holder = $store->session();
    $waiter = $store->session();
    $holder->begin();
    fakeFreshClaim($holder);

    $store->whenWaiting(200, static function () use ($holder): void {
        $holder->rollBack();
    });

    $waiter->begin();
    $inFlight = fakeClaim($waiter, 100);

    expect($inFlight)->toBeInstanceOf(InFlight::class)
        ->and($inFlight instanceof InFlight ? $inFlight->waited->milliseconds : null)->toBe(100)
        ->and($holder->inTransaction())->toBeTrue()
        ->and($store->scheduledWaitEvents())->toBe(1)
        ->and(fakeClaim($waiter, 200))->toBeInstanceOf(Fresh::class)
        ->and($store->scheduledWaitEvents())->toBe(0);
});

it('runs due events in time order and stops once the claim is free', function (): void {
    $store = new FakeIdempotencyStore;
    $holder = $store->session();
    $waiter = $store->session();
    $holder->begin();
    fakeFreshClaim($holder);
    $ran = [];

    $store->whenWaiting(30, static function () use ($holder, &$ran): void {
        $ran[] = 30;
        $holder->commit();
    });
    $store->whenWaiting(10, static function () use (&$ran): void {
        $ran[] = 10;
    });
    $store->whenWaiting(40, static function () use (&$ran): void {
        $ran[] = 40;
    });

    $waiter->begin();

    expect(fakeClaim($waiter, 50))->toBeInstanceOf(Fresh::class)
        ->and($ran)->toBe([10, 30])
        ->and($store->scheduledWaitEvents())->toBe(1);
});

it('waits in real time: until each event, and the whole budget before it is in flight', function (): void {
    $store = new FakeIdempotencyStore;
    $holder = $store->session();
    $waiter = $store->session();
    $holder->begin();
    fakeFreshClaim($holder);
    $at = [];
    $started = hrtime(true);

    $store->whenWaiting(60, static function () use ($started, &$at): void {
        $at[] = (hrtime(true) - $started) / 1_000_000;
    });

    $waiter->begin();
    $result = fakeClaim($waiter, 150);
    $waited = (hrtime(true) - $started) / 1_000_000;

    expect($result)->toBeInstanceOf(InFlight::class)
        ->and($at)->toHaveCount(1)
        ->and($at[0] ?? 0.0)->toBeGreaterThanOrEqual(60.0)
        ->and($at[0] ?? PHP_FLOAT_MAX)->toBeLessThan(150.0)
        ->and($waited)->toBeGreaterThanOrEqual(150.0);
});

it('does not sleep for a claim nobody else holds, or for a contested claim without a budget', function (): void {
    $store = new FakeIdempotencyStore;
    $holder = $store->session();
    $waiter = $store->session();
    $started = hrtime(true);

    $holder->begin();
    expect(fakeClaim($holder, 5000))->toBeInstanceOf(Fresh::class);
    $waiter->begin();
    expect(fakeClaim($waiter))->toBeInstanceOf(InFlight::class)
        ->and((hrtime(true) - $started) / 1_000_000)->toBeLessThan(1000.0);
});

it('starts a holder that completes its claim and ends it as the wait event says', function (): void {
    $clock = new FakeClock;
    $store = new FakeIdempotencyStore($clock);
    $scope = IdempotencyScope::forActor(new PrincipalId('user:7'), new CommandName('entry.release'));
    $changesetId = new ChangesetId(new FakeIdGenerator(clock: $clock)->next());
    $store->holdWhileWaiting($scope, new IdempotencyKey('retry-me'), ContentHash::of('{"value":"A"}'), $changesetId, HolderEnd::Commit, 20);
    $waiter = $store->session();
    $waiter->begin();

    expect(fakeClaim($waiter))->toBeInstanceOf(InFlight::class)
        ->and($store->scheduledWaitEvents())->toBe(1)
        ->and(fakeClaim($waiter, 20))->toEqual(new Replay($changesetId))
        ->and($store->scheduledWaitEvents())->toBe(0);
});

it('refuses a holder on a key that is not fresh, and holds nothing', function (): void {
    $store = new FakeIdempotencyStore;
    $scope = IdempotencyScope::forActor(new PrincipalId('user:7'), new CommandName('entry.release'));
    $session = $store->session();
    $session->begin();
    fakeFreshClaim($session);

    expect(fn () => $store->holdWhileWaiting($scope, new IdempotencyKey('retry-me'), ContentHash::of('{"value":"A"}'), null, HolderEnd::RollBack, 0))
        ->toThrow(LogicException::class, 'The holder needs a fresh key, and the claim on [actor user:7 entry.release retry-me] is '.InFlight::class.'.');

    $session->rollBack();
    $session->begin();

    expect(fakeClaim($session))->toBeInstanceOf(Fresh::class)
        ->and($store->scheduledWaitEvents())->toBe(0);
});

it('runs no event for a claim nobody else holds', function (): void {
    $store = new FakeIdempotencyStore;
    $store->whenWaiting(0, static function (): void {
        throw new LogicException('An uncontested claim ran a wait event.');
    });
    $session = $store->session();
    $session->begin();

    expect(fakeClaim($session, 100))->toBeInstanceOf(Fresh::class)
        ->and($store->scheduledWaitEvents())->toBe(1);
});

it('refuses a wait event before the wait starts', function (): void {
    expect(fn () => new FakeIdempotencyStore()->whenWaiting(-1, static function (): void {}))
        ->toThrow(InvalidArgumentException::class, 'after 0 or more milliseconds, got -1');
});

it('keeps each claim under its scope and key', function (): void {
    expect(FakeIdempotencyStore::claimName(IdempotencyScope::forSource(new PrincipalId('feed:ap'), new CommandName('entry.create')), new IdempotencyKey('ap:42:v7')))
        ->toBe('source feed:ap entry.create ap:42:v7');
});

it('refuses an uncovered range that ends before it starts', function (): void {
    $store = new FakeIdempotencyStore;
    $at = $store->clock()->now();

    expect(fn () => $store->uncover($at, $at->modify('-1 microsecond')))->toThrow(InvalidArgumentException::class, 'ends at or after it starts');
});

it('dates a record at the later of the Clock and the changeset, and names its table', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2031-05-01T10:00:00Z'));
    $store = new FakeIdempotencyStore($clock);
    $store->uncover(new DateTimeImmutable('2031-05-02T00:00:00Z'), new DateTimeImmutable('2031-05-02T23:59:59.999999Z'));
    $early = new ChangesetId(new FakeIdGenerator(clock: new FakeClock(new DateTimeImmutable('2031-05-01T09:00:00Z')))->next());
    $late = new ChangesetId(new FakeIdGenerator(clock: new FakeClock(new DateTimeImmutable('2031-05-02T09:00:00Z')))->next());
    $session = $store->session();

    $session->begin();
    expect(fn () => $session->complete(fakeFreshClaim($session)->token, $late))->toThrow(PartitionMissing::class, 'No partition of table "idempotency_keys" covers the row');
    $session->rollBack();

    $session->begin();
    $session->complete(fakeFreshClaim($session)->token, $early);
    $session->rollBack();

    $clock->set(new DateTimeImmutable('2031-05-02T12:00:00Z'));
    $session->begin();
    expect(fn () => $session->complete(fakeFreshClaim($session)->token, $early))->toThrow(PartitionMissing::class);
    $session->rollBack();
});

it('refuses a claim, a complete and a commit after PartitionMissing until the transaction rolls back', function (): void {
    $clock = new FakeClock;
    $store = new FakeIdempotencyStore($clock);
    $store->uncover($clock->now()->setTime(0, 0), $clock->now()->modify('+1 day'));
    $changesetId = new ChangesetId(new FakeIdGenerator(clock: $clock)->next());
    $session = $store->session();
    $session->begin();
    $token = fakeFreshClaim($session)->token;

    expect(fn () => $session->complete($token, $changesetId))->toThrow(PartitionMissing::class);

    foreach ([
        fn (): ClaimResult => fakeClaim($session),
        fn () => $session->complete($token, $changesetId),
        $session->commit(...),
    ] as $call) {
        expect($call)->toThrow(LogicException::class, 'Roll it back.');
    }

    expect($session->inTransaction())->toBeTrue();
    $session->rollBack();

    $other = $store->session();
    $other->begin();

    expect(fakeClaim($other))->toBeInstanceOf(Fresh::class);
});
