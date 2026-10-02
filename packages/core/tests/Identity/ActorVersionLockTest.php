<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Identity;

use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Identity\Adapter\PostgresActorVersionLock;
use Cbox\Cms\Core\Pipeline\Domain\LockStrength;
use Illuminate\Database\ConnectionResolver;
use InvalidArgumentException;
use Throwable;
use UnexpectedValueException;

/*
 * The actor version lock apart from Postgres: it locks only actors, reads the lookup's answer from
 * the write connection with the strength as a boolean, and refuses an answer that is no positive
 * integer as text. EntryVersionLocksTest locks a real actor's row.
 */

/**
 * A lock whose connection is $connection, named identity.
 */
function actorLockOn(AnsweringConnection $connection): PostgresActorVersionLock
{
    return new PostgresActorVersionLock(new ConnectionResolver(['identity' => $connection]), 'identity');
}

const LOCKED_ACTOR = '0192a0c0-0000-7000-8000-00000000a0a1';

it('asks the lookup on the write connection, with the strength as a boolean, and gives the version or null', function (): void {
    $connections = [new AnsweringConnection('3'), new AnsweringConnection('1'), new AnsweringConnection(null)];
    $version = actorLockOn($connections[0])->lock(ActorId::fromString(LOCKED_ACTOR), LockStrength::Update);
    $shared = actorLockOn($connections[1])->lock(ActorId::fromString(LOCKED_ACTOR), LockStrength::Share);
    $missing = actorLockOn($connections[2])->lock(ActorId::fromString(LOCKED_ACTOR), LockStrength::Share);
    $calls = [...$connections[0]->calls, ...$connections[1]->calls, ...$connections[2]->calls];

    expect($version)->toEqual(new AggregateVersion(3))
        ->and($shared)->toEqual(new AggregateVersion(1))
        ->and($missing)->toBeNull()
        ->and($calls)->toBe([
            [PostgresActorVersionLock::LOCK, [LOCKED_ACTOR, 'true'], false],
            [PostgresActorVersionLock::LOCK, [LOCKED_ACTOR, 'false'], false],
            [PostgresActorVersionLock::LOCK, [LOCKED_ACTOR, 'false'], false],
        ]);
});

it('refuses an aggregate that is no actor before it asks the lookup', function (): void {
    $connection = new AnsweringConnection('1');
    $entry = EntryId::fromString('0192a0c0-0000-7000-8000-00000000a0e1');
    $refused = null;

    try {
        actorLockOn($connection)->lock($entry, LockStrength::Share);
    } catch (Throwable $thrown) {
        $refused = $thrown;
    }

    expect($refused)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($refused?->getMessage())->toBe(sprintf('The actor version lock locks actors, not "%s".', $entry->aggregateKey()))
        ->and($connection->calls)->toBe([]);
});

it('refuses an answer that is no positive integer as text', function (mixed $answer, string $type): void {
    $refused = null;

    try {
        actorLockOn(new AnsweringConnection($answer))->lock(ActorId::fromString(LOCKED_ACTOR), LockStrength::Share);
    } catch (Throwable $thrown) {
        $refused = $thrown;
    }

    expect($refused)->toBeInstanceOf(UnexpectedValueException::class)
        ->and($refused?->getMessage())->toBe("The version of an actor is a positive integer, got {$type}.");
})->with([
    'an integer' => [7, 'int'],
    'a word' => ['seven', 'string'],
    'zero' => ['0', 'string'],
    'a leading zero' => ['07', 'string'],
    'a trailing line' => ["7\n", 'string'],
]);
