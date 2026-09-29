<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Infrastructure;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * Row level security for a generated type table (PRD 4.1, 5.10), for the migration that creates
 * it, as the owner role.
 *
 * A type table carries the system columns cms_entry_id, cms_stage, cms_home_node and
 * cms_owner_actor (PRD 4.1). protect() enables and forces row level security on it and adds the
 * two policies every type table has, over the access migration's policy functions:
 *
 * - `<table>_actor`, for every command: cms_access_home(cms_home_node, cms_owner_actor), which
 *   holds for the owning actor of an owned type and, with EXISTS against `nodes`, for an actor
 *   whose regions reach the row's home node. The same test is the WITH CHECK, so a write cannot
 *   move a row out of the actor's regions.
 * - `<table>_released`, for reads: cms_access_released(cms_entry_id, cms_stage), the released
 *   stage of an entry with a live placement, which every context reads, the anonymous one
 *   included.
 *
 * Without an actor context neither holds, so the app role reads and writes no row. The name is a
 * table name as the generators write it, at most 54 characters so the policy names fit in 63.
 */
#[Experimental]
final readonly class TypeTableAccess
{
    private const string TABLE = '/\A[a-z][a-z0-9_]{0,53}\z/';

    public function __construct(private ConnectionInterface $connection) {}

    /**
     * @throws InvalidArgumentException when the name is not a table name of at most 54 characters
     */
    public function protect(string $table): void
    {
        if (preg_match(self::TABLE, $table) !== 1) {
            throw new InvalidArgumentException(sprintf('A type table is named by lowercase letters, digits and underscores, starting with a letter, at most 54 characters, got "%s".', addcslashes($table, "\0..\37\177..\377\"\\")));
        }

        $this->connection->statement(sprintf('alter table %s enable row level security', $table));
        $this->connection->statement(sprintf('alter table %s force row level security', $table));
        $this->connection->statement(sprintf(
            'create policy %1$s_actor on %1$s for all using (cms_access_home(cms_home_node, cms_owner_actor)) with check (cms_access_home(cms_home_node, cms_owner_actor))',
            $table,
        ));
        $this->connection->statement(sprintf(
            'create policy %1$s_released on %1$s for select using (cms_access_released(cms_entry_id, cms_stage))',
            $table,
        ));
    }
}
