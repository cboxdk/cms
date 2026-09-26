<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Doctor\Domain\Dto\DdlPrivileges;
use Cbox\Cms\Core\Doctor\Domain\Dto\PostgresRole;
use Cbox\Cms\Core\Doctor\Domain\Dto\PostgresVersion;
use Cbox\Cms\Core\Doctor\Domain\Dto\RoleMembership;
use Cbox\Cms\Core\Doctor\Domain\Dto\RowSecurity;
use Cbox\Cms\Core\Doctor\Domain\Dto\TimeoutSetting;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Doctor\Domain\Probes\PostgresProbe;
use Cbox\Cms\Core\Partitions\Boundary\CatalogRow;
use Override;

/**
 * The Postgres probe on the doctor's connection, which logs in as the app role (PRD 4.2). It only
 * reads settings and the system catalogs.
 */
#[Internal]
final readonly class ConnectionPostgresProbe implements PostgresProbe
{
    /**
     * The relations that count as owned: tables, partitioned tables, views, materialized views,
     * foreign tables and sequences outside the system and temporary schemas, for c in pg_class
     * joined with n in pg_namespace.
     */
    private const string OWNED_RELATIONS = <<<'SQL'
        c.relkind in ('r', 'p', 'v', 'm', 'f', 'S')
          and n.nspname not in ('pg_catalog', 'information_schema')
          and n.nspname not like 'pg\_temp\_%'
          and n.nspname not like 'pg\_toast%'
        SQL;

    public function __construct(private DoctorConnection $connection) {}

    #[Override]
    public function target(): string
    {
        return $this->connection->target();
    }

    #[Override]
    public function connect(): void
    {
        $this->connection->rows('select 1 as one');
    }

    #[Override]
    public function version(): PostgresVersion
    {
        $row = CatalogRow::one($this->connection->rows(
            "select current_setting('server_version_num')::int as number, current_setting('server_version')::text as text",
        ));

        return new PostgresVersion($row->int('number'), $row->string('text'));
    }

    /**
     * The role's own attributes, and the roles it is a member of, directly or through other roles
     * and whether or not the grant has INHERIT or SET, that are superusers, have BYPASSRLS or own
     * relations. Attributes are never inherited, but SET ROLE reaches them, and an owner's rights
     * pass to the members that inherit them.
     */
    #[Override]
    public function role(): PostgresRole
    {
        $row = CatalogRow::one($this->connection->rows(
            'select r.rolname::text as name, r.rolsuper as superuser, r.rolbypassrls as bypass from pg_roles r where r.rolname = current_user',
        ));

        $memberships = array_map(
            static fn (CatalogRow $membership): RoleMembership => new RoleMembership(
                $membership->string('name'),
                $membership->bool('superuser'),
                $membership->bool('bypass'),
                $membership->bool('owns_relations'),
            ),
            CatalogRow::all($this->connection->rows(sprintf(<<<'SQL'
                select name, superuser, bypass, owns_relations
                from (
                    select m.rolname::text as name,
                           m.rolsuper as superuser,
                           m.rolbypassrls as bypass,
                           exists (
                               select 1
                               from pg_class c
                               join pg_namespace n on n.oid = c.relnamespace
                               where c.relowner = m.oid
                                 and %s
                           ) as owns_relations
                    from pg_roles m
                    where m.rolname <> current_user
                      and pg_has_role(current_user, m.oid, 'MEMBER')
                ) memberships
                where superuser or bypass or owns_relations
                order by name
                SQL, self::OWNED_RELATIONS))),
        );

        return new PostgresRole($row->string('name'), $row->bool('superuser'), $row->bool('bypass'), $memberships);
    }

    #[Override]
    public function transactionTimeout(): TimeoutSetting
    {
        $rows = $this->connection->rows(
            "select current_user::text as role, s.setting::bigint as milliseconds, s.source::text as source from pg_settings s where s.name = 'transaction_timeout'",
        );

        if ($rows === []) {
            throw ProbeFailed::violation('The server has no setting transaction_timeout; it arrived in Postgres 17.');
        }

        $row = CatalogRow::one($rows);

        return new TimeoutSetting($row->string('role'), $row->int('milliseconds'), $row->string('source'));
    }

    #[Override]
    public function maxPreparedTransactions(): int
    {
        return CatalogRow::one($this->connection->rows("select current_setting('max_prepared_transactions')::int as prepared"))->int('prepared');
    }

    #[Override]
    public function ddlPrivileges(): DdlPrivileges
    {
        // A member of the owner can alter and drop a relation like its owner: with INHERIT it has
        // the owner's rights, with SET it can become the owner.
        $owned = sprintf(<<<'SQL'
            from pg_class c
            join pg_namespace n on n.oid = c.relnamespace
            where pg_has_role(current_user, c.relowner, 'MEMBER')
              and %s
            SQL, self::OWNED_RELATIONS);

        $summary = CatalogRow::one($this->connection->rows(<<<SQL
            select current_user::text as role,
                   current_database()::text as database,
                   has_database_privilege(current_database(), 'CREATE') as create_on_database,
                   (select count(*) {$owned})::int as owned_count
            SQL));

        // How many owned relations the cause names.
        $shown = 5;
        $names = array_map(
            static fn (CatalogRow $row): string => $row->string('name'),
            CatalogRow::all($this->connection->rows(sprintf("select format('%%I.%%I', n.nspname, c.relname) as name %s order by 1 limit %d", $owned, $shown))),
        );

        $owners = array_map(
            static fn (CatalogRow $row): string => $row->string('name'),
            CatalogRow::all($this->connection->rows(sprintf('select distinct pg_get_userbyid(c.relowner)::text as name %s order by 1', $owned))),
        );

        $schemas = array_map(
            static fn (CatalogRow $row): string => $row->string('name'),
            CatalogRow::all($this->connection->rows(<<<'SQL'
                select nspname::text as name
                from pg_namespace
                where has_schema_privilege(oid, 'CREATE')
                  and nspname not like 'pg\_%'
                  and nspname <> 'information_schema'
                order by 1
                SQL)),
        );

        return new DdlPrivileges(
            role: $summary->string('role'),
            database: $summary->string('database'),
            ownedRelations: $names,
            ownedCount: $summary->int('owned_count'),
            ownerRoles: $owners,
            createOnDatabase: $summary->bool('create_on_database'),
            schemasWithCreate: $schemas,
        );
    }

    /**
     * Tables and partitioned tables, partitions included, since a partition read directly applies
     * its own row level security and not its parent's.
     */
    #[Override]
    public function rowSecurity(): RowSecurity
    {
        $tables = <<<'SQL'
            from pg_class c
            join pg_namespace n on n.oid = c.relnamespace
            where c.relkind in ('r', 'p')
              and c.relrowsecurity
              and n.nspname not in ('pg_catalog', 'information_schema')
              and n.nspname not like 'pg\_temp\_%'
              and n.nspname not like 'pg\_toast%'
            SQL;

        $summary = CatalogRow::one($this->connection->rows(<<<SQL
            select current_database()::text as database,
                   (select count(*) {$tables})::int as enabled_count,
                   (select count(*) {$tables} and not c.relforcerowsecurity)::int as unforced_count
            SQL));

        // How many unforced tables the cause names; parents come before partitions.
        $shown = 5;
        $names = array_map(
            static fn (CatalogRow $row): string => $row->string('name'),
            CatalogRow::all($this->connection->rows(sprintf(
                "select format('%%I.%%I', n.nspname, c.relname) as name %s and not c.relforcerowsecurity order by c.relispartition, 1 limit %d",
                $tables,
                $shown,
            ))),
        );

        return new RowSecurity(
            database: $summary->string('database'),
            enabledCount: $summary->int('enabled_count'),
            unforcedTables: $names,
            unforcedCount: $summary->int('unforced_count'),
        );
    }
}
