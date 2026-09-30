<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Access\Infrastructure\ActorContext;
use Cbox\Cms\Core\Entries\Adapter\PostgresEntryVersionLock;
use Cbox\Cms\Core\Entries\Adapter\PostgresVariantVersionLock;
use Cbox\Cms\Core\Pipeline\Domain\LockStrength;
use Cbox\Cms\Core\Structure\Adapter\PostgresNodeVersionLock;
use Cbox\Cms\Core\Tests\Entries\EntryFields;
use Cbox\Cms\Core\Tests\Entries\EntryWorld;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The version locks of the entry commands' aggregates on Postgres (PRD 6.2 phase 7): the entry's
 * row in `entries`, the head's in `variant_heads` and the node's in `nodes`, as the app role under
 * the actor context. Each gives the row's version, or null for a row that does not exist, and holds
 * the lock until the transaction ends: Share lets another session lock the row for share but not
 * change it, and Update lets it neither, while a row that only references the locked one through a
 * foreign key is never blocked (FOR NO KEY UPDATE).
 */

/**
 * The world with an article created on HOME.
 */
function lockedWorld(): EntryWorld
{
    EntryWorld::seed();
    $world = new EntryWorld;
    $world->create(EntryWorld::type(EntryWorld::ARTICLE)->id, EntryFields::article());

    return $world;
}

afterEach(function (): void {
    if (DB::connection()->transactionLevel() > 0) {
        DB::connection()->rollBack();
    }

    EntryWorld::cleanUp();
});

/**
 * Whether another session gets the row lock at once, as the superuser, past row level security.
 */
function otherSessionLocks(string $table, string $where, string $key, string $clause): bool
{
    $superuser = StorageTables::superuser();
    $superuser->beginTransaction();

    try {
        $superuser->select(sprintf('select 1 from %s where %s for %s nowait', $table, $where, $clause), [$key]);

        return true;
    } catch (QueryException $exception) {
        if ($exception->getCode() !== '55P03') {
            throw $exception;
        }

        return false;
    } finally {
        $superuser->rollBack();
    }
}

it('locks the row of its aggregate at the strength asked, and gives its version', function (string $kind): void {
    $world = lockedWorld();
    $connections = app(ConnectionResolverInterface::class);
    [$lock, $aggregate, $table, $where, $key] = match ($kind) {
        'entry' => [new PostgresEntryVersionLock($connections), EntryWorld::entry(), 'entries', 'id = ?', EntryWorld::ENTRY],
        'variant' => [new PostgresVariantVersionLock($connections), new VariantRef(EntryWorld::entry(), VariantKey::shared()), 'variant_heads', "entry_id = ? and variant = 'shared'", EntryWorld::ENTRY],
        default => [new PostgresNodeVersionLock($connections), EntryWorld::home(), 'nodes', 'id = ?', EntryWorld::HOME],
    };
    $connection = DB::connection();

    $connection->beginTransaction();
    new ActorContext($connections)->set($world->access());
    $shared = $lock->lock($aggregate, LockStrength::Share);
    $sharedLocks = [otherSessionLocks($table, $where, $key, 'share'), otherSessionLocks($table, $where, $key, 'no key update')];
    $connection->rollBack();

    $connection->beginTransaction();
    new ActorContext($connections)->set($world->access());
    $updated = $lock->lock($aggregate, LockStrength::Update);
    $updateLocks = [otherSessionLocks($table, $where, $key, 'key share'), otherSessionLocks($table, $where, $key, 'share')];
    $connection->rollBack();

    expect($lock->kind())->toBe($kind)
        ->and($shared)->toEqual(new AggregateVersion(1))
        ->and($updated)->toEqual(new AggregateVersion(1))
        ->and($sharedLocks)->toBe([true, false])
        ->and($updateLocks)->toBe([true, false]);
})->with(['entry', 'variant', 'node']);

it('gives no version for an aggregate whose row does not exist', function (): void {
    $world = lockedWorld();
    $connections = app(ConnectionResolverInterface::class);
    $missing = EntryId::fromString('0192a0c0-0000-7000-8000-0000000001e8');
    DB::connection()->beginTransaction();
    new ActorContext($connections)->set($world->access());

    expect(new PostgresEntryVersionLock($connections)->lock($missing, LockStrength::Update))->toBeNull()
        ->and(new PostgresVariantVersionLock($connections)->lock(new VariantRef($missing, VariantKey::shared()), LockStrength::Update))->toBeNull()
        ->and(new PostgresNodeVersionLock($connections)->lock(NodeId::fromString(EntryWorld::NOWHERE), LockStrength::Share))->toBeNull();
});
