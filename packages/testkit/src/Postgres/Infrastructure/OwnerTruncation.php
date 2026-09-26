<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Postgres\Infrastructure;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Illuminate\Database\Connection;
use LogicException;

/**
 * Removes the rows that Postgres tests really committed.
 *
 * The suite never wraps a test in a transaction, so the rows stay after the test. This helper
 * truncates every table in the owner connection's search path, except the migration log, as
 * the owner role: the app role has no TRUNCATE privilege (PRD 4.2). Partitions are truncated
 * through their parent. Identity sequences restart.
 *
 * A lock timeout keeps a leftover transaction from hanging the run: if a test left a lock
 * behind, the truncate fails with SQLSTATE 55P03 instead of waiting.
 */
#[Experimental]
final readonly class OwnerTruncation
{
    public const string LOCK_TIMEOUT = '5s';

    /**
     * @param  list<string>  $keep  tables that keep their rows
     * @param  string  $lockTimeout  how long the truncate waits for a lock, in Postgres syntax
     */
    public function __construct(
        private Connection $owner,
        private array $keep = ['migrations'],
        private string $lockTimeout = self::LOCK_TIMEOUT,
    ) {}

    /**
     * Truncates the tables and returns their names, sorted.
     *
     * @return list<string>
     */
    public function truncate(): array
    {
        $tables = $this->tables();

        if ($tables === []) {
            return [];
        }

        $this->owner->statement('select set_config(\'lock_timeout\', ?, false)', [$this->lockTimeout]);

        try {
            $this->owner->statement(sprintf(
                'truncate table %s restart identity cascade',
                implode(', ', array_map($this->quote(...), $tables)),
            ));
        } finally {
            $this->owner->statement('reset lock_timeout');
        }

        return $tables;
    }

    /**
     * The ordinary and partitioned tables in the search path, without partitions and without
     * the tables to keep.
     *
     * @return list<string> schema-qualified names
     */
    public function tables(): array
    {
        $rows = $this->owner->select(
            <<<'SQL'
                select n.nspname || '.' || c.relname as name
                from pg_class c
                join pg_namespace n on n.oid = c.relnamespace
                where n.nspname = any (current_schemas(false))
                  and c.relkind in ('r', 'p')
                  and not c.relispartition
                order by n.nspname, c.relname
                SQL,
        );

        $tables = [];

        foreach ($rows as $row) {
            $name = is_object($row) && property_exists($row, 'name') ? $row->name : null;

            if (! is_string($name)) {
                throw new LogicException('Expected a table name from pg_class.');
            }

            $tables[] = $name;
        }

        return array_values(array_filter(
            $tables,
            fn (string $table): bool => ! in_array(substr($table, (int) strpos($table, '.') + 1), $this->keep, true),
        ));
    }

    private function quote(string $qualified): string
    {
        return implode('.', array_map(
            static fn (string $part): string => '"'.str_replace('"', '""', $part).'"',
            explode('.', $qualified, 2),
        ));
    }
}
