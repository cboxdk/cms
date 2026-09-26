<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\IdempotencyStore;

use Cbox\Cms\Contracts\Idempotency\ContentHash;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Idempotency\IdempotencyScope;
use Cbox\Cms\Core\IdempotencyStore\Adapter\ClaimLock;
use Cbox\Cms\Core\IdempotencyStore\Adapter\ClaimsInTransaction;
use LogicException;

/*
 * The transaction-local record of Fresh claims that complete() checks.
 */

function claimLock(string $key): ClaimLock
{
    return ClaimLock::of(IdempotencyScope::forActor('user:7', 'entry.release'), new IdempotencyKey($key));
}

it('starts empty from no setting and from the empty setting Postgres leaves after the transaction', function (?string $setting): void {
    $claims = ClaimsInTransaction::parse($setting);

    expect($claims->isFresh(claimLock('a'), ContentHash::of('x')))->toBeFalse()
        ->and($claims->isCompleted(claimLock('a')))->toBeFalse()
        ->and($claims->toSetting())->toBe('');
})->with(['null' => [null], 'empty' => ['']]);

it('holds a Fresh claim with its content hash until it is completed', function (): void {
    $a = claimLock('a');
    $b = claimLock('b');
    $claims = ClaimsInTransaction::parse(null)->withFresh($a, ContentHash::of('x'))->withFresh($b, ContentHash::of('y'));

    expect($claims->isFresh($a, ContentHash::of('x')))->toBeTrue()
        ->and($claims->isFresh($a, ContentHash::of('y')))->toBeFalse()
        ->and($claims->isFresh($b, ContentHash::of('y')))->toBeTrue()
        ->and($claims->isFresh(claimLock('c'), ContentHash::of('x')))->toBeFalse();

    $completed = ClaimsInTransaction::parse($claims->withCompleted($a)->toSetting());

    expect($completed->isFresh($a, ContentHash::of('x')))->toBeFalse()
        ->and($completed->isCompleted($a))->toBeTrue()
        ->and($completed->isFresh($b, ContentHash::of('y')))->toBeTrue()
        ->and($completed->toSetting())->toBe($a->digest.':completed,'.$b->digest.':'.ContentHash::of('y')->value);
});

it('replaces the hash of a claim made again in the same transaction', function (): void {
    $a = claimLock('a');
    $claims = ClaimsInTransaction::parse(null)->withFresh($a, ContentHash::of('x'))->withFresh($a, ContentHash::of('y'));

    expect($claims->isFresh($a, ContentHash::of('y')))->toBeTrue()
        ->and($claims->isFresh($a, ContentHash::of('x')))->toBeFalse()
        ->and(substr_count($claims->toSetting(), ':'))->toBe(1);
});

it('refuses a setting the store never writes', function (string $setting): void {
    expect(static fn (): ClaimsInTransaction => ClaimsInTransaction::parse($setting))
        ->toThrow(LogicException::class, 'cbox_cms.idempotency_claims');
})->with([
    'no separator' => [str_repeat('a', 64)],
    'a short digest' => ['abc:completed'],
    'an unknown state' => [str_repeat('a', 64).':done'],
    'three parts' => [str_repeat('a', 64).':completed:x'],
    'upper case' => [str_repeat('A', 64).':completed'],
]);
