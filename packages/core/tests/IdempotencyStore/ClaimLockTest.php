<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\IdempotencyStore;

use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Idempotency\IdempotencyScope;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\PrincipalId;
use Cbox\Cms\Core\IdempotencyStore\Adapter\ClaimLock;

/*
 * The claim's name and advisory lock key. The encoding is fixed: a changed lock key would let
 * processes on the old and the new code claim the same key at once, so the vectors are pinned.
 */

it('derives the digest and the lock key from the versioned encoding of the scope and key', function (string $key, string $digest, int $lockKey): void {
    $lock = ClaimLock::of(IdempotencyScope::forActor(new PrincipalId('user:7'), new CommandName('entry.release')), new IdempotencyKey($key));

    expect($lock->digest)->toBe($digest)
        ->and($lock->key)->toBe($lockKey)
        ->and(sprintf('%016x', $lock->key))->toBe(substr($digest, 0, 16));
})->with([
    'a positive lock key' => ['retry-me', '7b3b5dba614837af1974d079eac12e5d6eb54501bc15f97cc62a7f8b390ba02e', 8879794145368487855],
    'a negative lock key, the top bit set' => ['e', '9167ce490bb5d9156f950775d0d92e1dc5013acd8736a2616d396a75f5fe89dd', -7969174202484401899],
]);

it('gives a different claim for every part of the scope and the key', function (): void {
    $claims = [
        ClaimLock::of(IdempotencyScope::forActor(new PrincipalId('user:7'), new CommandName('entry.release')), new IdempotencyKey('k')),
        ClaimLock::of(IdempotencyScope::forSource(new PrincipalId('user:7'), new CommandName('entry.release')), new IdempotencyKey('k')),
        ClaimLock::of(IdempotencyScope::forActor(new PrincipalId('user:8'), new CommandName('entry.release')), new IdempotencyKey('k')),
        ClaimLock::of(IdempotencyScope::forActor(new PrincipalId('user:7'), new CommandName('entry.publish')), new IdempotencyKey('k')),
        ClaimLock::of(IdempotencyScope::forActor(new PrincipalId('user:7'), new CommandName('entry.release')), new IdempotencyKey('k2')),
        // The same characters split differently between principal and key.
        ClaimLock::of(IdempotencyScope::forActor(new PrincipalId('user:7k'), new CommandName('entry.release')), new IdempotencyKey('2')),
        ClaimLock::of(IdempotencyScope::forActor(new PrincipalId('user:7"'), new CommandName('entry.release')), new IdempotencyKey('k')),
    ];

    $digests = array_map(static fn (ClaimLock $lock): string => $lock->digest, $claims);
    $keys = array_map(static fn (ClaimLock $lock): int => $lock->key, $claims);

    expect(array_unique($digests))->toHaveCount(count($claims))
        ->and(array_unique($keys))->toHaveCount(count($claims))
        ->and(ClaimLock::of(IdempotencyScope::forActor(new PrincipalId('user:7'), new CommandName('entry.release')), new IdempotencyKey('k')))->toEqual($claims[0])
        ->and(ClaimLock::VERSION)->toBe('cbox_cms.idempotency.v1');
});
