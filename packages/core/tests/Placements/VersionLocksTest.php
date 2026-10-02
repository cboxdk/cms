<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Placements;

use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Pipeline\Domain\LockStrength;
use Cbox\Cms\Core\Pipeline\Domain\VersionLock;
use Cbox\Cms\Core\Placements\Adapter\PostgresPlacementVersionLock;
use Cbox\Cms\Core\Structure\Adapter\PostgresSiteVersionLock;
use Cbox\Cms\Core\Tests\Identity\AnsweringConnection;
use Illuminate\Database\ConnectionResolver;
use InvalidArgumentException;
use Throwable;

/*
 * The placement's and the site's version locks apart from Postgres: each asks its lookup on the
 * write connection with the strength as a boolean, gives the version or null for a missing row,
 * and locks only its own kind of aggregate.
 */

const LOCKED_PLACEMENT = '0192a0c0-0000-7000-8000-00000000f2b1';
const LOCKED_SITE = '0192a0c0-0000-7000-8000-00000000f2c1';

function versionLockOn(string $kind, AnsweringConnection $connection): VersionLock
{
    $connections = new ConnectionResolver(['locks' => $connection]);

    return $kind === 'placement' ? new PostgresPlacementVersionLock($connections, 'locks') : new PostgresSiteVersionLock($connections, 'locks');
}

function lockedAggregate(string $kind): AggregateRef
{
    return $kind === 'placement' ? PlacementId::fromString(LOCKED_PLACEMENT) : SiteId::fromString(LOCKED_SITE);
}

it('asks the lookup on the write connection with the strength, and gives the version or null', function (string $kind, string $lock, string $id): void {
    $updated = new AnsweringConnection(4);
    $shared = new AnsweringConnection(2);
    $missing = new AnsweringConnection(null);

    expect(versionLockOn($kind, $updated)->lock(lockedAggregate($kind), LockStrength::Update))->toEqual(new AggregateVersion(4))
        ->and(versionLockOn($kind, $shared)->lock(lockedAggregate($kind), LockStrength::Share))->toEqual(new AggregateVersion(2))
        ->and(versionLockOn($kind, $missing)->lock(lockedAggregate($kind), LockStrength::Share))->toBeNull()
        ->and([...$updated->calls, ...$shared->calls])->toBe([[$lock, [$id, '1'], false], [$lock, [$id, ''], false]]);
})->with([
    'placement' => ['placement', PostgresPlacementVersionLock::LOCK, LOCKED_PLACEMENT],
    'site' => ['site', PostgresSiteVersionLock::LOCK, LOCKED_SITE],
]);

it('locks only its own kind of aggregate', function (string $kind, string $message): void {
    $connection = new AnsweringConnection(1);
    $entry = EntryId::fromString('0192a0c0-0000-7000-8000-00000000f2e1');
    $refused = null;

    try {
        versionLockOn($kind, $connection)->lock($entry, LockStrength::Share);
    } catch (Throwable $thrown) {
        $refused = $thrown;
    }

    expect($refused)->toBeInstanceOf(InvalidArgumentException::class)
        ->and($refused?->getMessage())->toBe(sprintf($message, $entry->aggregateKey()))
        ->and($connection->calls)->toBe([]);
})->with([
    'placement' => ['placement', 'The placement version lock locks placements, not "%s".'],
    'site' => ['site', 'The site version lock locks sites, not "%s".'],
]);
