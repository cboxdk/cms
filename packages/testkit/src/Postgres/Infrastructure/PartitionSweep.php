<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Postgres\Infrastructure;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Illuminate\Database\Connection;
use LogicException;

/**
 * Drops the leaf partitions in the test schema, one statement each: before migrate:fresh drops the
 * rest, and after each test, before the owner truncates the tables.
 *
 * migrate:fresh drops every table in one statement, and the truncation after a test truncates every
 * partitioned table with all its partitions in one statement, which holds a lock on each table,
 * partition, index and TOAST table at once. The tests create partitions for the dates they write
 * at, a daily partitioned table gets one per day of every range a test covers, and the partitions
 * pile up in the checkout's database from test to test and from run to run. Past a few thousand
 * relations that one statement runs out of the server's shared lock table ("out of shared memory",
 * SQLSTATE 53200), which every checkout on the server shares. Dropping each leaf partition in a
 * statement of its own keeps the locks of any statement small. A test therefore finds no partition
 * an earlier test made, and covers the dates it writes at itself (PartitionFixtures), as every test
 * already does so that it passes alone.
 */
#[Experimental]
final readonly class PartitionSweep
{
    public function __construct(private Connection $owner) {}

    /**
     * Drops every leaf partition in the search path and returns their names, sorted.
     *
     * @return list<string> schema-qualified names
     */
    public function drop(): array
    {
        $dropped = [];

        foreach ($this->owner->select(
            <<<'SQL'
                select quote_ident(n.nspname) || '.' || quote_ident(c.relname) as name
                from pg_class c
                join pg_namespace n on n.oid = c.relnamespace
                where n.nspname = any (current_schemas(false))
                  and c.relkind = 'r'
                  and c.relispartition
                order by 1
                SQL,
        ) as $row) {
            $name = is_object($row) && property_exists($row, 'name') ? $row->name : null;

            if (! is_string($name)) {
                throw new LogicException('Expected a partition name from pg_class.');
            }

            $this->owner->statement('drop table if exists '.$name);
            $dropped[] = $name;
        }

        return $dropped;
    }
}
