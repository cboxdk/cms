<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Doctor\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Doctor\Adapter\ConnectionPostgresProbe;
use Cbox\Cms\Core\Doctor\Adapter\DoctorConnection;
use Cbox\Cms\Core\Doctor\Domain\Dto\PostgresRole;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Partitions\Boundary\CatalogRow;
use Cbox\Cms\Identity\CredentialStore\Boundary\IdentityConfig;
use Cbox\Cms\Identity\CredentialStore\Domain\CredentialStore;
use Cbox\Cms\Identity\Doctor\Domain\Probes\CredentialStoreProbe;
use Override;

/**
 * The credential store probe on two doctor connections: a copy of the identity connection with a
 * short connect timeout, `cms_doctor_identity`, and the doctor's copy of the app role's
 * connection, `cms_doctor`. It only reads settings and the system catalogs, and it reads the app
 * role's privileges as the app role, so a privilege that reaches it through a membership or
 * PUBLIC counts.
 */
#[Internal]
final readonly class ConnectionCredentialStoreProbe implements CredentialStoreProbe
{
    /** The copy of the identity connection. */
    public const string IDENTITY_CONNECTION = 'cms_doctor_identity';

    /**
     * Each privilege of the app role on the store's schema and on the relations in it, as the
     * app role: on a table any column privilege counts, so a grant of one column is found too.
     */
    private const string APP_PRIVILEGES = <<<'SQL'
        select format('%s on schema %I', p.privilege, n.nspname) as privilege
        from pg_namespace n
        cross join unnest(array['USAGE', 'CREATE']) as p (privilege)
        where n.nspname = ?
          and has_schema_privilege(current_user, n.oid, p.privilege)
        union all
        select format('%s on %I.%I', p.privilege, n.nspname, c.relname)
        from pg_class c
        join pg_namespace n on n.oid = c.relnamespace
        cross join unnest(array['SELECT', 'INSERT', 'UPDATE', 'REFERENCES']) as p (privilege)
        where n.nspname = ?
          and c.relkind in ('r', 'p', 'v', 'm', 'f')
          and has_any_column_privilege(current_user, c.oid, p.privilege)
        union all
        select format('%s on %I.%I', p.privilege, n.nspname, c.relname)
        from pg_class c
        join pg_namespace n on n.oid = c.relnamespace
        cross join unnest(array['DELETE', 'TRUNCATE', 'TRIGGER', 'MAINTAIN']) as p (privilege)
        where n.nspname = ?
          and c.relkind in ('r', 'p', 'v', 'm', 'f')
          and has_table_privilege(current_user, c.oid, p.privilege)
        union all
        select format('%s on %I.%I', p.privilege, n.nspname, c.relname)
        from pg_class c
        join pg_namespace n on n.oid = c.relnamespace
        cross join unnest(array['USAGE', 'SELECT', 'UPDATE']) as p (privilege)
        where n.nspname = ?
          and c.relkind = 'S'
          and has_sequence_privilege(current_user, c.oid, p.privilege)
        order by 1
        SQL;

    /**
     * @param  ?DoctorConnection  $identity  the copy of the identity connection, or null when cbox-cms.identity.connection names none
     */
    public function __construct(
        private DoctorConnection $app,
        private ?DoctorConnection $identity,
    ) {}

    #[Override]
    public function target(): string
    {
        return $this->identity?->target() ?? sprintf('no connection, because %s.connection names none', IdentityConfig::KEY);
    }

    #[Override]
    public function identityLogin(): string
    {
        return CatalogRow::one($this->identity()->rows('select current_user::text as name'))->string('name');
    }

    #[Override]
    public function appLogin(): string
    {
        return CatalogRow::one($this->app->rows('select current_user::text as name'))->string('name');
    }

    #[Override]
    public function schemaOwner(): ?string
    {
        $rows = CatalogRow::all($this->identity()->rows(
            'select pg_get_userbyid(n.nspowner)::text as owner from pg_namespace n where n.nspname = ?',
            [CredentialStore::SCHEMA],
        ));

        return $rows === [] ? null : $rows[0]->string('owner');
    }

    #[Override]
    public function identityRole(): PostgresRole
    {
        return new ConnectionPostgresProbe($this->identity())->role();
    }

    #[Override]
    public function appPrivileges(): array
    {
        return array_map(
            static fn (CatalogRow $row): string => $row->string('privilege'),
            CatalogRow::all($this->app->rows(self::APP_PRIVILEGES, array_fill(0, 4, CredentialStore::SCHEMA))),
        );
    }

    /**
     * @throws ProbeFailed violation when cbox-cms.identity.connection names no connection
     */
    private function identity(): DoctorConnection
    {
        return $this->identity ?? throw ProbeFailed::violation(sprintf(
            '%s.connection names no database connection.',
            IdentityConfig::KEY,
        ));
    }
}
