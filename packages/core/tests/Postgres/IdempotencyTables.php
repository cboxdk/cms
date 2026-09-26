<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Idempotency\ContentHash;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Idempotency\IdempotencyScope;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\Uuid7;
use Cbox\Cms\Core\IdempotencyStore\Adapter\ClaimLock;
use Cbox\Cms\Core\IdempotencyStore\Adapter\PostgresIdempotencyStore;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use LogicException;

/**
 * Helpers for the idempotency store's Postgres tests: the default claim, changeset ids at an
 * instant, and rows and advisory locks read as the owner role.
 */
final class IdempotencyTables
{
    public static function scope(): IdempotencyScope
    {
        return IdempotencyScope::forActor('user:7', 'entry.release');
    }

    public static function key(string $value = 'retry-me'): IdempotencyKey
    {
        return new IdempotencyKey($value);
    }

    public static function hash(string $content = '{"title":"A"}'): ContentHash
    {
        return ContentHash::of($content);
    }

    public static function changeset(string $instant, int $sequence = 0): ChangesetId
    {
        return new ChangesetId(Uuid7::lowestAt(Uuid7::unixMillisecondsOf(new DateTimeImmutable($instant)) + $sequence));
    }

    /**
     * The committed records of a key, counted as the owner, which sees every row whatever the
     * Clock says.
     */
    public static function rows(IdempotencyKey $key): int
    {
        $count = ReceiptTables::owner()->scalar(sprintf('select count(*) from %s where idempotency_key = ?', PostgresIdempotencyStore::TABLE), [$key->value]);

        return is_int($count) ? $count : throw new LogicException('Expected a count.');
    }

    /**
     * The leaf partitions that hold the committed records of a key.
     *
     * @return list<string>
     */
    public static function partitionsOf(IdempotencyKey $key): array
    {
        return ReceiptTables::texts(
            ReceiptTables::owner(),
            sprintf('select distinct tableoid::regclass::text as value from %s where idempotency_key = ? order by 1', PostgresIdempotencyStore::TABLE),
            [$key->value],
        );
    }

    /**
     * The advisory locks on the claim's lock key that any backend holds or waits for. A bigint key
     * shows in pg_locks as classid (the high 32 bits) and objid (the low 32 bits), objsubid 1.
     */
    public static function locks(IdempotencyScope $scope, IdempotencyKey $key): int
    {
        $lock = ClaimLock::of($scope, $key)->key;
        $count = ReceiptTables::owner()->scalar(
            "select count(*) from pg_locks where locktype = 'advisory' and objsubid = 1 and classid::bigint = ? and objid::bigint = ?",
            [($lock >> 32) & 0xFFFFFFFF, $lock & 0xFFFFFFFF],
        );

        return is_int($count) ? $count : throw new LogicException('Expected a count.');
    }

    /**
     * The transaction-local claims setting on a connection, as text.
     */
    public static function claimsSetting(Connection $connection): string
    {
        $value = $connection->scalar('select current_setting(?, true)', [PostgresIdempotencyStore::CLAIMS_SETTING]);

        return is_string($value) ? $value : '';
    }
}
