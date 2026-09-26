<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Database\Infrastructure;

use Cbox\Cms\Contracts\Attributes\Experimental;
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
 * is written into a migration, so the same migration works whatever the roles are called.
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
    /** The table privileges of Postgres 17, the only words written into GRANT. */
    public const array PRIVILEGES = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER', 'MAINTAIN'];

    public function __construct(private ConnectionInterface $connection) {}

    /**
     * Leaves the roles that have privileges on the table, other than its owner, with exactly
     * $privileges on the table and on every partition below it.
     *
     * @param  string  $table  a table name as regclass reads it, in the search path or qualified
     * @param  list<string>  $privileges  from PRIVILEGES; empty takes everything away
     */
    public function limitTo(string $table, array $privileges): void
    {
        $privileges = array_values(array_unique(array_map($this->privilege(...), $privileges)));
        $roles = array_values(array_unique(array_map(
            static fn (TableGrant $grant): string => $grant->role,
            $this->grants($table),
        )));

        foreach ($this->tree($table) as $relation) {
            foreach ($this->rolesOn($relation, $roles) as $role) {
                $this->connection->statement(sprintf('revoke all on table %s from %s', $relation, $role));
            }

            if ($privileges === []) {
                continue;
            }

            foreach ($roles as $role) {
                $this->connection->statement(sprintf('grant %s on table %s to %s', implode(', ', $privileges), $relation, $role));
            }
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
            static fn (CatalogRow $row): TableGrant => new TableGrant($row->string('role'), $row->string('privilege'), $row->bool('grantable')),
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
     * The roles to revoke from on the relation: the given ones and every other role with a grant
     * on it, other than the owner.
     *
     * @param  list<string>  $roles
     * @return list<string>
     */
    private function rolesOn(string $relation, array $roles): array
    {
        $granted = array_map(static fn (TableGrant $grant): string => $grant->role, $this->grants($relation));

        return array_values(array_unique([...$roles, ...$granted]));
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
            $roles[$grant->role][$this->privilege($grant->privilege)] = $grant->grantable;
        }

        return $roles;
    }

    /**
     * @param  list<string>  $privileges
     */
    private function list(array $privileges): string
    {
        return implode(', ', array_map($this->privilege(...), $privileges));
    }

    private function privilege(string $privilege): string
    {
        $upper = strtoupper($privilege);

        if (! in_array($upper, self::PRIVILEGES, true)) {
            throw new LogicException(sprintf('[%s] is not a table privilege. Use one of %s.', $privilege, implode(', ', self::PRIVILEGES)));
        }

        return $upper;
    }
}
