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
 * A partition is a table of its own with its own privileges. Reading and writing through the
 * parent checks only the parent's, but a role can also address a partition directly. limitTo()
 * therefore narrows every partition below the table as well, and the partition manager gives each
 * partition it creates the grants of its parent with copy().
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
     * Gives $to the grants $from has, for every role but the owner, and takes away every other
     * grant on $to. Only the differences are written: a table that already has its parent's
     * grants gets no statement.
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
