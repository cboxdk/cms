<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Doctor\Domain\Dto\DdlPrivileges;
use Cbox\Cms\Core\Doctor\Domain\Dto\PostgresRole;
use Cbox\Cms\Core\Doctor\Domain\Dto\PostgresVersion;
use Cbox\Cms\Core\Doctor\Domain\Dto\TimeoutSetting;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Doctor\Domain\Probes\PostgresProbe;
use Cbox\Cms\Core\Partitions\Boundary\CatalogRow;
use Override;
use Throwable;

/**
 * The Postgres probe on the doctor's connection, which logs in as the app role (PRD 4.2). It only
 * reads settings and the system catalogs.
 */
#[Internal]
final readonly class ConnectionPostgresProbe implements PostgresProbe
{
    /** How many owned relations the cause names. */
    public const int OWNED_SHOWN = 5;

    public function __construct(private DoctorConnection $connection) {}

    #[Override]
    public function target(): string
    {
        return $this->connection->target();
    }

    #[Override]
    public function connect(): void
    {
        $this->rows('select 1 as one');
    }

    #[Override]
    public function version(): PostgresVersion
    {
        $row = CatalogRow::one($this->rows(
            "select current_setting('server_version_num')::int as number, current_setting('server_version')::text as text",
        ));

        return new PostgresVersion($row->int('number'), $row->string('text'));
    }

    #[Override]
    public function role(): PostgresRole
    {
        $row = CatalogRow::one($this->rows(
            'select r.rolname::text as name, r.rolsuper as superuser, r.rolbypassrls as bypass from pg_roles r where r.rolname = current_user',
        ));

        return new PostgresRole($row->string('name'), $row->bool('superuser'), $row->bool('bypass'));
    }

    #[Override]
    public function transactionTimeout(): TimeoutSetting
    {
        $rows = $this->rows(
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
        return CatalogRow::one($this->rows("select current_setting('max_prepared_transactions')::int as prepared"))->int('prepared');
    }

    #[Override]
    public function ddlPrivileges(): DdlPrivileges
    {
        $owned = <<<'SQL'
            from pg_class c
            join pg_namespace n on n.oid = c.relnamespace
            where c.relowner = (select oid from pg_roles where rolname = current_user)
              and c.relkind in ('r', 'p', 'v', 'm', 'f', 'S')
              and n.nspname not in ('pg_catalog', 'information_schema')
              and n.nspname not like 'pg\_temp\_%'
              and n.nspname not like 'pg\_toast%'
            SQL;

        $summary = CatalogRow::one($this->rows(<<<SQL
            select current_user::text as role,
                   current_database()::text as database,
                   has_database_privilege(current_database(), 'CREATE') as create_on_database,
                   (select count(*) {$owned})::int as owned_count
            SQL));

        $names = array_map(
            static fn (CatalogRow $row): string => $row->string('name'),
            CatalogRow::all($this->rows(sprintf("select format('%%I.%%I', n.nspname, c.relname) as name %s order by 1 limit %d", $owned, self::OWNED_SHOWN))),
        );

        $schemas = array_map(
            static fn (CatalogRow $row): string => $row->string('name'),
            CatalogRow::all($this->rows(<<<'SQL'
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
            createOnDatabase: $summary->bool('create_on_database'),
            schemasWithCreate: $schemas,
        );
    }

    /**
     * @return list<mixed>
     *
     * @throws ProbeFailed
     */
    private function rows(string $sql): array
    {
        $connection = $this->connection->get();

        try {
            return array_values($connection->select($sql, [], false));
        } catch (Throwable $thrown) {
            throw PostgresErrors::classify($thrown);
        }
    }
}
