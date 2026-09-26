<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/*
 * PRD 4.1: a foreign key always has an index on the referencing side, and a partial index does
 * not count. The check reads pg_constraint against pg_index for the whole schema the migrations
 * built, so it covers every table that is added later. The probes show that the query finds a
 * foreign key without an index, and one covered only by a partial index or by an index that
 * does not lead with the key's columns.
 */

/**
 * The foreign keys in the connection's schema that no index covers: an index that is not
 * partial and whose leading columns are exactly the key's columns, in any order.
 *
 * @return list<string> table.constraint
 */
function unindexedForeignKeys(Connection $connection): array
{
    $foreignKeys = [];

    foreach ($connection->select(
        <<<'SQL'
                select c.conrelid::regclass::text || '.' || c.conname::text as foreign_key
                from pg_constraint c
                where c.contype = 'f'
                  and c.connamespace = any (select oid from pg_namespace where nspname = any (current_schemas(false)))
                  and not exists (
                      select 1
                      from pg_index i
                      where i.indrelid = c.conrelid
                        and i.indpred is null
                        and i.indnkeyatts >= cardinality(c.conkey)
                        and (i.indkey::int2[])[0:cardinality(c.conkey) - 1] @> c.conkey
                  )
                order by 1
                SQL,
    ) as $row) {
        $foreignKey = is_object($row) && property_exists($row, 'foreign_key') ? $row->foreign_key : null;
        $foreignKeys[] = is_string($foreignKey) ? $foreignKey : throw new LogicException('Expected a foreign key name.');
    }

    return $foreignKeys;
}

it('finds no foreign key without an index on its referencing side in the migrated schema', function (): void {
    $owner = DB::connection('pgsql_owner');

    expect(unindexedForeignKeys($owner))->toBe([]);
});

it('finds a foreign key without an index, and one covered only by a partial or a trailing index', function (): void {
    $owner = DB::connection('pgsql_owner');
    $owner->beginTransaction();

    try {
        $owner->statement('create table fk_probe_parent (id bigint primary key, other bigint not null, unique (id, other))');
        $owner->statement('create table fk_probe_bare (id bigint primary key, parent_id bigint references fk_probe_parent (id))');
        $owner->statement('create table fk_probe_partial (id bigint primary key, parent_id bigint references fk_probe_parent (id))');
        $owner->statement('create index on fk_probe_partial (parent_id) where parent_id is not null');
        $owner->statement('create table fk_probe_trailing (id bigint primary key, parent_id bigint references fk_probe_parent (id))');
        $owner->statement('create index on fk_probe_trailing (id, parent_id)');
        $owner->statement('create table fk_probe_covered (id bigint primary key, parent_id bigint references fk_probe_parent (id))');
        $owner->statement('create index on fk_probe_covered (parent_id, id)');
        $owner->statement('create table fk_probe_pair (id bigint primary key, parent_id bigint, other bigint, foreign key (parent_id, other) references fk_probe_parent (id, other))');
        $owner->statement('create index on fk_probe_pair (other, parent_id)');

        expect(unindexedForeignKeys($owner))->toBe([
            'fk_probe_bare.fk_probe_bare_parent_id_fkey',
            'fk_probe_partial.fk_probe_partial_parent_id_fkey',
            'fk_probe_trailing.fk_probe_trailing_parent_id_fkey',
        ]);
    } finally {
        $owner->rollBack();
    }

    expect(unindexedForeignKeys($owner))->toBe([]);
});
