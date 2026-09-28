<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\Uuid7;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\StoredReceipt;
use Cbox\Cms\Contracts\ReceiptStore;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Helpers for the receipt store's Postgres tests: fixture receipts, row counts read as the owner
 * role, and the caller's own table for the tests of one shared transaction.
 */
final class ReceiptTables
{
    /** A table the tests write to as the caller, next to the receipt, in the same transaction. */
    public const string CALLER_TABLE = 'receipt_caller_writes';

    public static function owner(): Connection
    {
        return DB::connection('pgsql_owner');
    }

    public static function receipt(string $instant, RetentionClass $retention = RetentionClass::Standard, int $sequence = 0): StoredReceipt
    {
        $id = Uuid7::lowestAt(Uuid7::unixMillisecondsOf(new DateTimeImmutable($instant)) + $sequence);

        return new StoredReceipt(new ChangesetId($id), $retention, [
            ProjectionStatus::pending(new ProjectionName('edge')),
            ProjectionStatus::pending(new ProjectionName('fragments')),
            ProjectionStatus::pending(new ProjectionName('search')),
        ]);
    }

    /**
     * Stores the receipts with $store in one transaction on $connection and commits it, as the
     * command kernel stores a receipt in the command transaction. $store runs on $connection.
     */
    public static function commit(Connection $connection, ReceiptStore $store, StoredReceipt ...$receipts): void
    {
        $connection->transaction(static function () use ($store, $receipts): void {
            foreach ($receipts as $receipt) {
                $store->store($receipt);
            }
        });
    }

    /**
     * The rows for the changeset in a receipt table, counted as the owner, which sees every
     * committed row whatever the Clock says.
     */
    public static function rows(string $table, ChangesetId $changesetId): int
    {
        $count = self::owner()->scalar(sprintf('select count(*) from %s where changeset_id = ?', $table), [$changesetId->toString()]);

        return is_int($count) ? $count : throw new LogicException('Expected a count.');
    }

    public static function callerRows(): int
    {
        $count = self::owner()->scalar(sprintf('select count(*) from %s', self::CALLER_TABLE));

        return is_int($count) ? $count : throw new LogicException('Expected a count.');
    }

    public static function createCallerTable(): void
    {
        self::dropCallerTable();
        self::owner()->statement(sprintf('create table %s (id bigint primary key, note text not null)', self::CALLER_TABLE));
    }

    public static function dropCallerTable(): void
    {
        $owner = self::owner();
        $owner->statement("set lock_timeout = '5s'");
        $owner->statement(sprintf('drop table if exists %s', self::CALLER_TABLE));
        $owner->statement('reset lock_timeout');
    }

    /**
     * One text column of every row a query returns, in order. Numbers and booleans are printed.
     *
     * @param  list<mixed>  $bindings
     * @return list<string>
     */
    public static function texts(Connection $connection, string $sql, array $bindings = [], string $column = 'value'): array
    {
        $values = [];

        foreach ($connection->select($sql, $bindings) as $row) {
            $value = is_object($row) && property_exists($row, $column) ? $row->{$column} : null;

            $values[] = match (true) {
                is_string($value) => $value,
                is_int($value) => (string) $value,
                is_bool($value) => $value ? 'true' : 'false',
                default => throw new LogicException(sprintf('Expected the column %s as text.', $column)),
            };
        }

        return $values;
    }

    /**
     * The leaf partitions an EXPLAIN of the statement scans, without running it.
     *
     * @param  list<mixed>  $bindings
     * @return list<string>
     */
    public static function scannedLeaves(Connection $connection, string $sql, array $bindings): array
    {
        $plan = $connection->scalar('explain (analyze off, format json) '.$sql, $bindings);

        if (! is_string($plan)) {
            throw new LogicException('Expected a JSON plan.');
        }

        $decoded = json_decode($plan, true, flags: JSON_THROW_ON_ERROR);
        $relations = [];
        self::collectRelations($decoded, $relations);

        sort($relations);

        return $relations;
    }

    /**
     * @param  list<string>  $relations
     */
    private static function collectRelations(mixed $node, array &$relations): void
    {
        if (! is_array($node)) {
            return;
        }

        if (isset($node['Relation Name']) && is_string($node['Relation Name'])) {
            $relations[] = $node['Relation Name'];
        }

        foreach ($node as $child) {
            self::collectRelations($child, $relations);
        }
    }
}
