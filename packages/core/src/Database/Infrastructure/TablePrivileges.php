<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Database\Infrastructure;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Database\Domain\TablePrivilege;
use Cbox\Cms\Core\Partitions\Boundary\CatalogRow;
use Illuminate\Database\ConnectionInterface;
use LogicException;

/**
 * Narrows and copies table privileges, as the owner role (PRD 4.2, GUARDRAILS 6).
 *
 * The owner's default privileges give the app role SELECT, INSERT, UPDATE and DELETE on every
 * table the owner creates, so a new table is usable at once. A migration whose table needs less,
 * such as an append-only table, narrows it with limitTo(). The roles it narrows are the ones the
 * table grants to now, other than the owner: the roles the default privileges named. No role name
 * is written into a migration, so the same migration works whatever the roles are called. It only
 * takes privileges away: a role the default privileges gave less than the list, or PUBLIC, keeps
 * what it had and gains nothing.
 *
 * A table whose rows may change only in some columns keeps UPDATE on those columns alone with
 * limitColumns(): the role then cannot rewrite a key, move a row to another partition or touch a
 * column the code never writes, because Postgres checks the column privileges of every column an
 * UPDATE sets.
 *
 * A partition is a table of its own with its own privileges. Reading and writing through the
 * parent checks only the parent's, but a role can also address a partition directly. limitTo()
 * and limitColumns() therefore narrow every partition below the table as well, and the partition
 * manager gives each partition it creates the grants of its parent, on the table and on its
 * columns, with copy().
 *
 * Addon migrations may use it too, so it is not internal.
 */
#[Experimental]
final readonly class TablePrivileges
{
    public function __construct(private ConnectionInterface $connection) {}

    /**
     * Takes every privilege that is not in $privileges away from the roles with grants on the table,
     * other than its owner, PUBLIC included, and then gives every partition below it the table's
     * grants with copy(). It only narrows: a role keeps the privileges it holds that are in the list,
     * with their grant option, and gains none, so a role the installation gave less than the list,
     * or PUBLIC, is never widened. A partition gets only what a role holds on the table.
     *
     * @param  string  $table  a table name as regclass reads it, in the search path or qualified
     * @param  list<TablePrivilege>  $privileges  empty takes everything away
     */
    public function limitTo(string $table, array $privileges): void
    {
        $relation = $this->relation($table);
        $kept = $this->words($privileges);

        foreach ($this->byRole($this->grants($relation)) as $role => $held) {
            $revoke = array_values(array_diff(array_keys($held), $kept));

            if ($revoke !== []) {
                $this->connection->statement(sprintf('revoke %s on table %s from %s', $this->list($revoke), $relation, $role));
            }
        }

        foreach (array_slice($this->tree($relation), 1) as $partition) {
            $this->copy($relation, $partition);
        }
    }

    /**
     * Narrows $privilege on the table to $columns. Every role with $privilege on the whole table,
     * other than its owner, PUBLIC included, loses it on the table and gets it on those columns
     * alone, with the grant option it had. It only narrows: a role that holds $privilege on no
     * column, or only on some columns, keeps what it has and gains nothing. Every partition below
     * the table then gets the table's grants with copy(), column grants included.
     *
     * Revoking a privilege on a table also revokes it on each of the table's columns, so a role
     * this narrows ends with $privilege on exactly $columns.
     *
     * @param  string  $table  a table name as regclass reads it, in the search path or qualified
     * @param  TablePrivilege  $privilege  SELECT, INSERT, UPDATE or REFERENCES, the privileges a column has
     * @param  list<string>  $columns  column names as the catalog holds them, unquoted; empty takes the privilege away
     *
     * @throws LogicException for a privilege no column has, or a column the table does not have
     */
    public function limitColumns(string $table, TablePrivilege $privilege, array $columns): void
    {
        // The privileges Postgres grants on a column as well as on a whole table.
        $onColumns = match ($privilege) {
            TablePrivilege::Select, TablePrivilege::Insert, TablePrivilege::Update, TablePrivilege::References => true,
            TablePrivilege::Delete, TablePrivilege::Truncate, TablePrivilege::Trigger, TablePrivilege::Maintain => false,
        };

        if (! $onColumns) {
            throw new LogicException(sprintf('Postgres grants %s only on a whole table, not on its columns.', $privilege->value));
        }

        $relation = $this->relation($table);
        $quoted = $this->columns($relation, $columns);

        foreach ($this->grants($relation) as $grant) {
            if ($grant->privilege !== $privilege) {
                continue;
            }

            $this->connection->statement(sprintf('revoke %s on table %s from %s', $privilege->value, $relation, $grant->role));

            if ($quoted !== []) {
                $this->connection->statement(sprintf(
                    'grant %s (%s) on table %s to %s%s',
                    $privilege->value,
                    implode(', ', $quoted),
                    $relation,
                    $grant->role,
                    $grant->grantable ? ' with grant option' : '',
                ));
            }
        }

        foreach (array_slice($this->tree($relation), 1) as $partition) {
            $this->copy($relation, $partition);
        }
    }

    /**
     * Gives $to the grants $from has, on the table and on its columns, for every role but the
     * owner, and takes away every other grant on $to. Columns are matched by name, as a partition
     * has its parent's columns. Only the differences are written: a table that already has its
     * parent's grants gets no statement.
     *
     * @param  string  $from  a table name as regclass reads it
     * @param  string  $to  a table name as regclass reads it
     */
    public function copy(string $from, string $to): void
    {
        $relation = $this->relation($to);
        $wanted = $this->byRole($this->grants($from));
        $current = $this->byRole($this->grants($relation));

        foreach ($current as $role => $privileges) {
            $revoke = array_keys(array_diff_key($privileges, $wanted[$role] ?? []));
            $revokeOption = array_keys(array_filter(
                array_intersect_key($privileges, $wanted[$role] ?? []),
                static fn (bool $grantable, string $privilege): bool => $grantable && ! ($wanted[$role][$privilege] ?? false),
                ARRAY_FILTER_USE_BOTH,
            ));

            if ($revoke !== []) {
                $this->connection->statement(sprintf('revoke %s on table %s from %s', $this->list($revoke), $relation, $role));
            }

            if ($revokeOption !== []) {
                $this->connection->statement(sprintf('revoke grant option for %s on table %s from %s', $this->list($revokeOption), $relation, $role));
            }
        }

        foreach ($wanted as $role => $privileges) {
            foreach ([false, true] as $grantable) {
                $grant = array_keys(array_filter(
                    $privileges,
                    static fn (bool $wantedOption, string $privilege): bool => $wantedOption === $grantable
                        && (! isset($current[$role][$privilege]) || ($grantable && ! $current[$role][$privilege])),
                    ARRAY_FILTER_USE_BOTH,
                ));

                if ($grant !== []) {
                    $this->connection->statement(sprintf(
                        'grant %s on table %s to %s%s',
                        $this->list($grant),
                        $relation,
                        $role,
                        $grantable ? ' with grant option' : '',
                    ));
                }
            }
        }

        // Read after the table grants, because revoking a privilege on the table revokes it on
        // each column too.
        $this->copyColumns($from, $relation);
    }

    /**
     * The grants on single columns of the table to roles other than its owner, in role, column
     * and privilege order. A privilege on the whole table is not listed per column; grants()
     * reads those. The role and the column are quoted for SQL, and PUBLIC is `public`.
     *
     * @return list<ColumnGrant>
     */
    public function columnGrants(string $table): array
    {
        return array_map(
            static fn (CatalogRow $row): ColumnGrant => new ColumnGrant(
                $row->string('role'),
                $row->string('column_name'),
                TablePrivilege::tryFrom($row->string('privilege'))
                    ?? throw new LogicException(sprintf('Postgres reports the column privilege [%s], which TablePrivilege does not know.', $row->string('privilege'))),
                $row->bool('grantable'),
            ),
            CatalogRow::all($this->connection->select(
                <<<'SQL'
                    select case when a.grantee = 0 then 'public' else quote_ident(r.rolname) end as role,
                           quote_ident(att.attname) as column_name,
                           a.privilege_type::text as privilege,
                           a.is_grantable as grantable
                    from pg_class c
                    join pg_attribute att on att.attrelid = c.oid and att.attnum > 0 and not att.attisdropped
                    cross join lateral aclexplode(att.attacl) a
                    left join pg_roles r on r.oid = a.grantee
                    where c.oid = ?::regclass
                      and a.grantee <> c.relowner
                    order by 1, 2, 3
                    SQL,
                [$this->relation($table)],
            )),
        );
    }

    /**
     * The column part of copy(): the column grants of $from on $to, and no others.
     *
     * @param  string  $from  a table name as regclass reads it
     * @param  string  $to  the table as relation() prints it
     */
    private function copyColumns(string $from, string $to): void
    {
        $wanted = $this->columnsByRole($this->columnGrants($from));
        $current = $this->columnsByRole($this->columnGrants($to));

        foreach ($current as $role => $privileges) {
            foreach ($privileges as $privilege => $columns) {
                $keep = $wanted[$role][$privilege] ?? [];
                $revoke = array_keys(array_diff_key($columns, $keep));
                $revokeOption = array_keys(array_filter(
                    array_intersect_key($columns, $keep),
                    static fn (bool $grantable, string $column): bool => $grantable && ! $keep[$column],
                    ARRAY_FILTER_USE_BOTH,
                ));

                if ($revoke !== []) {
                    $this->connection->statement(sprintf('revoke %s (%s) on table %s from %s', $privilege, implode(', ', $revoke), $to, $role));
                }

                if ($revokeOption !== []) {
                    $this->connection->statement(sprintf('revoke grant option for %s (%s) on table %s from %s', $privilege, implode(', ', $revokeOption), $to, $role));
                }
            }
        }

        foreach ($wanted as $role => $privileges) {
            foreach ($privileges as $privilege => $columns) {
                foreach ([false, true] as $grantable) {
                    $grant = array_keys(array_filter(
                        $columns,
                        static fn (bool $wantedOption, string $column): bool => $wantedOption === $grantable
                            && (! isset($current[$role][$privilege][$column]) || ($grantable && ! $current[$role][$privilege][$column])),
                        ARRAY_FILTER_USE_BOTH,
                    ));

                    if ($grant !== []) {
                        $this->connection->statement(sprintf(
                            'grant %s (%s) on table %s to %s%s',
                            $privilege,
                            implode(', ', $grant),
                            $to,
                            $role,
                            $grantable ? ' with grant option' : '',
                        ));
                    }
                }
            }
        }
    }

    /**
     * The named columns of the table, quoted for SQL, once each and in the table's order.
     *
     * @param  list<string>  $columns  column names as the catalog holds them
     * @return list<string>
     */
    private function columns(string $relation, array $columns): array
    {
        $quoted = [];

        foreach (CatalogRow::all($this->connection->select(
            <<<'SQL'
                select attname::text as name, quote_ident(attname) as quoted
                from pg_attribute
                where attrelid = ?::regclass and attnum > 0 and not attisdropped
                order by attnum
                SQL,
            [$relation],
        )) as $row) {
            $quoted[$row->string('name')] = $row->string('quoted');
        }

        foreach ($columns as $column) {
            if (! isset($quoted[$column])) {
                throw new LogicException(sprintf('The table [%s] has no column [%s].', $relation, $column));
            }
        }

        return array_values(array_intersect_key($quoted, array_flip($columns)));
    }

    /**
     * The grants on the table to roles other than its owner, in role and privilege order. The
     * role is quoted for SQL, and PUBLIC is `public`.
     *
     * @return list<TableGrant>
     */
    public function grants(string $table): array
    {
        return array_map(
            static fn (CatalogRow $row): TableGrant => new TableGrant(
                $row->string('role'),
                TablePrivilege::tryFrom($row->string('privilege'))
                    ?? throw new LogicException(sprintf('Postgres reports the table privilege [%s], which TablePrivilege does not know.', $row->string('privilege'))),
                $row->bool('grantable'),
            ),
            CatalogRow::all($this->connection->select(
                <<<'SQL'
                    select case when a.grantee = 0 then 'public' else quote_ident(r.rolname) end as role,
                           a.privilege_type::text as privilege,
                           a.is_grantable as grantable
                    from pg_class c
                    cross join lateral aclexplode(c.relacl) a
                    left join pg_roles r on r.oid = a.grantee
                    where c.oid = ?::regclass
                      and a.grantee <> c.relowner
                    order by 1, 2
                    SQL,
                [$this->relation($table)],
            )),
        );
    }

    /**
     * The table and every partition below it, as regclass prints them.
     *
     * @return list<string>
     */
    private function tree(string $table): array
    {
        $relation = $this->relation($table);

        $below = array_map(
            static fn (CatalogRow $row): string => $row->string('relation'),
            CatalogRow::all($this->connection->select(
                <<<'SQL'
                    select t.relid::regclass::text as relation
                    from pg_partition_tree(?::regclass) t
                    where t.relid <> ?::regclass
                    order by t.level, t.relid::regclass::text
                    SQL,
                [$relation, $relation],
            )),
        );

        return [$relation, ...$below];
    }

    /**
     * The table as regclass prints it: quoted where needed, and qualified when it is not in the
     * search path.
     */
    private function relation(string $table): string
    {
        $row = CatalogRow::one($this->connection->select(
            'select to_regclass(?)::text as relation',
            [$table],
        ));

        return $row->nullableString('relation') ?? throw new LogicException(sprintf('The table [%s] does not exist.', $table));
    }

    /**
     * @param  list<TableGrant>  $grants
     * @return array<string, array<string, bool>> role to privilege to grantable
     */
    private function byRole(array $grants): array
    {
        $roles = [];

        foreach ($grants as $grant) {
            $roles[$grant->role][$grant->privilege->value] = $grant->grantable;
        }

        return $roles;
    }

    /**
     * @param  list<ColumnGrant>  $grants
     * @return array<string, array<string, array<string, bool>>> role to privilege to column to grantable
     */
    private function columnsByRole(array $grants): array
    {
        $roles = [];

        foreach ($grants as $grant) {
            $roles[$grant->role][$grant->privilege->value][$grant->column] = $grant->grantable;
        }

        return $roles;
    }

    /**
     * The privileges as GRANT takes them, once each, in the order of TablePrivilege.
     *
     * @param  list<TablePrivilege>  $privileges
     * @return list<string>
     */
    private function words(array $privileges): array
    {
        return array_values(array_map(
            static fn (TablePrivilege $privilege): string => $privilege->value,
            array_filter(TablePrivilege::cases(), static fn (TablePrivilege $privilege): bool => in_array($privilege, $privileges, true)),
        ));
    }

    /**
     * @param  list<string>  $privileges  privileges as byRole() keys them, which are TablePrivilege values
     */
    private function list(array $privileges): string
    {
        return implode(', ', $privileges);
    }
}
