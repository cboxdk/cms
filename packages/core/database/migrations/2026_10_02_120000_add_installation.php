<?php

declare(strict_types=1);

use Cbox\Cms\Core\Database\Domain\TablePrivilege;
use Cbox\Cms\Core\Database\Infrastructure\TablePrivileges;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The installation and its operator (PRD 5.16, 3.3, 6.5 invariant 37), added by the owner role.
 *
 * The surface `maintenance`, the internal issuer of the maintenance commands an operator runs in
 * the maintenance process (cms:install and the commands of the first site, roles and staff), is
 * added to the CHECKs of `changesets.surface` and `audit.surface`.
 *
 * `installation` holds one row (`singleton` is true and the primary key): the installation
 * operator, the service actor the maintenance commands run as, the genesis changeset that created
 * it and its time. Every process and every deploy finds the operator here, never in .env or the
 * configuration. The foreign key to the changeset is deferred to the commit, because the genesis
 * writes the operator before its changeset, whose actor it is. The table has forced row level
 * security with a write policy for the owner role alone and no read policy: the app role keeps
 * SELECT and reads no row of it, and finds the operator through `cms_installation_operator()`
 * (SECURITY DEFINER as the owner role, with a fixed search_path), which returns its id or null.
 *
 * `cms_install_operator(actor, changeset, time)` is the genesis (SECURITY DEFINER, EXECUTE revoked
 * from PUBLIC, so only the owner role runs it): under an actor context whose actor is the operator
 * it installs, and only while `installation` has no row, it writes the operator, a service actor
 * registered and activated at once (state active, version 2, credential generation 1, no person
 * responsible for it), and the row of `installation`; with a row there it raises SQLSTATE 23505
 * and writes nothing. It locks `installation` first, so two installs run one after the other and
 * the second raises. The caller writes the genesis changeset, its audit row and its events in the
 * same transaction. It is the one place an actor is written active without a command run by an
 * active actor before it (invariant 37).
 *
 * The functions are created with CREATE OR REPLACE, because migrate:fresh drops tables and leaves
 * functions that do not depend on one.
 */
return new class extends Migration
{
    /** The issuers a changeset and its audit row may name, maintenance included. */
    private const string SURFACES = "'rest', 'inertia', 'mcp', 'cli', 'job', 'scheduler', 'subscriber', 'sidecar', 'seed', 'maintenance'";

    /** The issuers before this migration. */
    private const string SURFACES_BEFORE = "'rest', 'inertia', 'mcp', 'cli', 'job', 'scheduler', 'subscriber', 'sidecar', 'seed'";

    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        $this->surfaces(self::SURFACES);

        $connection->statement(<<<'SQL'
            create table installation (
                singleton boolean primary key default true,
                operator_actor_id uuid not null references actors (id),
                changeset_id uuid not null references changeset_register (changeset_id) deferrable initially deferred,
                installed_at timestamptz not null,
                constraint installation_singleton check (singleton),
                constraint installation_operator_actor_id_key unique (operator_actor_id)
            )
            SQL);
        $connection->statement('create index installation_changeset_id on installation (changeset_id)');
        $connection->statement('alter table installation enable row level security');
        $connection->statement('alter table installation force row level security');
        $connection->statement('create policy installation_owner_write on installation for all to current_user using (true) with check (true)');
        new TablePrivileges($connection)->limitTo('installation', [TablePrivilege::Select]);

        $this->definer(
            'cms_installation_operator() returns uuid language sql stable',
            'select operator_actor_id from installation',
        );

        $this->definer(
            'cms_install_operator(p_actor uuid, p_changeset uuid, p_at timestamptz) returns void language plpgsql volatile',
            <<<'SQL'
                begin
                    if cms_access_actor() is distinct from p_actor then
                        raise exception using
                            errcode = $$42501$$,
                            message = $$cms_install_operator runs only under the actor context of the operator it installs$$;
                    end if;

                    lock table installation in exclusive mode;

                    if exists (select 1 from installation) then
                        raise exception using
                            errcode = $$23505$$,
                            message = $$the installation has an operator already$$;
                    end if;

                    insert into actors (id, actor_class, state, version, credential_generation, created_at)
                    values (p_actor, $$service$$, $$active$$, 2, 1, p_at);

                    insert into installation (operator_actor_id, changeset_id, installed_at)
                    values (p_actor, p_changeset, p_at);
                end
                SQL,
        );
        $connection->statement('revoke execute on function cms_install_operator(uuid, uuid, timestamptz) from public');
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->statement('drop function cms_install_operator(uuid, uuid, timestamptz)');
        $connection->statement('drop function cms_installation_operator()');
        $connection->statement('drop table installation');
        $this->surfaces(self::SURFACES_BEFORE);
    }

    /**
     * Replaces the CHECKs of the surfaces on `changesets` and `audit`, which their partitions
     * inherit.
     */
    private function surfaces(string $surfaces): void
    {
        $connection = DB::connection($this->getConnection());

        foreach (['changesets', 'audit'] as $table) {
            $connection->statement(sprintf('alter table %1$s drop constraint %1$s_surface', $table));
            $connection->statement(sprintf('alter table %1$s add constraint %1$s_surface check (surface in (%2$s))', $table, $surfaces));
        }
    }

    /**
     * A function that runs as the owner role, with the current schema as its fixed search_path. The
     * body is a literal of the format() that creates it, so its quotes and percent signs are
     * doubled.
     */
    private function definer(string $signature, string $body): void
    {
        DB::connection($this->getConnection())->statement(sprintf(<<<'SQL'
            do $do$ begin
                execute format(
                    'create or replace function %s security definer set search_path = %%I, pg_temp as $body$ %s $body$',
                    current_schema()
                );
            end $do$
            SQL,
            $signature,
            str_replace(["'", '%'], ["''", '%%'], $body),
        ));
    }
};
