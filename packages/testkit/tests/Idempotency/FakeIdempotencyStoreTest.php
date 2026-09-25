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
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Idempotency\FakeIdempotencySession;
use Cbox\Cms\Testkit\Idempotency\FakeIdempotencyStore;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use InvalidArgumentException;
use LogicException;

/*
 * The fake's own behaviour beyond the shared suite: its sessions refuse nesting and stray
 * commits, and whenWaiting() models what happens while a contested claim waits within its budget.
 */

function fakeClaim(FakeIdempotencySession $session, int $budget = 0): ClaimResult
{
    return $session->claim(
        IdempotencyScope::forActor('user:7', 'entry.release'),
        new IdempotencyKey('retry-me'),
        ContentHash::of('{"title":"A"}'),
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

it('replays when the holder completes and commits within the wait budget', function (): void {
    $clock = new FakeClock;
    $store = new FakeIdempotencyStore($clock);
    $holder = $store->session();
    $waiter = $store->session();
    $changesetId = new ChangesetId(new FakeIdGenerator(clock: $clock)->next());
    $holder->begin();
    $token = fakeFreshClaim($holder)->token;

    $store->whenWaiting(40, static function () use ($holder, $token, $changesetId): void {
        $holder->complete($token, $changesetId);
        $holder->commit();
    });

    $waiter->begin();
    $result = fakeClaim($waiter, 50);

    expect($result)->toBeInstanceOf(Replay::class)
        ->and($result instanceof Replay ? $result->changesetId->toString() : null)->toBe($changesetId->toString())
        ->and($store->scheduledWaitEvents())->toBe(0);
});

it('is fresh when the holder rolls back within the wait budget', function (): void {
    $store = new FakeIdempotencyStore;
    $holder = $store->session();
    $waiter = $store->session();
    $holder->begin();
    fakeFreshClaim($holder);

    $store->whenWaiting(10, static function () use ($holder): void {
        $holder->rollBack();
    });

    $waiter->begin();

    expect(fakeClaim($waiter, 10))->toBeInstanceOf(Fresh::class);
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
    expect(FakeIdempotencyStore::claimName(IdempotencyScope::forSource('feed:ap', 'entry.create'), new IdempotencyKey('ap:42:v7')))
        ->toBe('source feed:ap entry.create ap:42:v7');
});
