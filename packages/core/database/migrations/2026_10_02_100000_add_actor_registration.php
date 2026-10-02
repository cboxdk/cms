<?php

declare(strict_types=1);

use Cbox\Cms\Core\Database\Domain\TablePrivilege;
use Cbox\Cms\Core\Database\Infrastructure\TablePrivileges;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * What actor.register and actor.activate need from the schema (PRD 5.16, 6.4, 12.2), added by the
 * owner role.
 *
 * `actors.responsible_actor_id` names the person responsible for a service actor (PRD 5.16: every
 * service actor has a named responsible person), an actor of its own, with an index for its
 * foreign key. Only a service actor has one (`actors_responsible_class`). Service actors written
 * before this migration, by the testkit's fixture writers, have none; actor.register always sets
 * one.
 *
 * `actor_profiles` holds an actor's profile, one row per actor: its display name and contact email
 * and the profile's version, which counts its changes from 1. Both values are personal data,
 * classified personal (PRD 12.2), and live apart from `actors`, so the actor directory, the events
 * and the audit never carry them. CHECKs hold them to the forms of DisplayName and EmailAddress. The
 * table has row level security, forced so the policies hold for the owner too (PRD 4.2): an actor
 * reads its own profile (`actor_profiles_own`), and an actor whose grants that have not ended hold
 * a role whose permissions name actor.list reads the profiles of staff actors, when its
 * classification access allows personal (`actor_profiles_listed`, through
 * `cms_access_profile_listed`). Without an actor context no row is read. The app role keeps SELECT
 * alone and has no write policy; the owner writes through the functions below.
 *
 * `cms_identity_register_actor(actor, version, class, responsible, display name, email, time,
 * changeset)` writes a registration as the owner role (SECURITY DEFINER, with a fixed search_path):
 * the actor, pending at version 1 and credential generation 1, and its profile at version 1. It
 * returns the credential generation. It runs only where the command runs: under an actor context,
 * in a transaction that wrote the changeset as an actor.register by the context's actor (the
 * changeset's xid is the transaction's). It refuses an end user, a staff actor with a responsible
 * person, and a service actor whose responsible person is not an active staff actor, as the action
 * does; anything else raises and writes nothing.
 *
 * `cms_identity_activate_actor(actor, version, changeset)` writes an activation the same way, in
 * the transaction of an actor.activate changeset by the context's actor: the actor becomes active
 * at the version given, and only from pending at the version before it, which the commit has
 * checked under its lock.
 *
 * The functions are created with CREATE OR REPLACE, because migrate:fresh drops tables and leaves
 * functions that do not depend on one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->statement(<<<'SQL'
            alter table actors
                add column responsible_actor_id uuid references actors (id),
                add constraint actors_responsible_class check (responsible_actor_id is null or actor_class = 'service')
            SQL);
        $connection->statement('create index actors_responsible_actor_id on actors (responsible_actor_id)');

        $connection->statement(<<<'SQL'
            create table actor_profiles (
                actor_id uuid primary key references actors (id),
                display_name text not null,
                email text not null,
                version bigint not null,
                constraint actor_profiles_display_name check (
                    display_name ~ '^[^[:space:][:cntrl:]]([^[:cntrl:]]*[^[:space:][:cntrl:]])?$'
                    and char_length(display_name) <= 200
                ),
                constraint actor_profiles_email check (
                    email ~ '^[^[:space:][:cntrl:]@]+@[^[:space:][:cntrl:]@]+\.[^[:space:][:cntrl:]@]+$'
                    and char_length(email) <= 254
                ),
                constraint actor_profiles_version check (version >= 1)
            )
            SQL);

        $this->definer(
            'cms_access_profile_listed(p_actor uuid) returns boolean language plpgsql stable',
            <<<'SQL'
                begin
                    if cms_access_actor() is null or not cms_access_classification_allows($$personal$$) then
                        return false;
                    end if;

                    return exists (select 1 from actors a where a.id = p_actor and a.actor_class = $$staff$$)
                        and exists (
                            select 1
                            from grants g
                            join role_permissions p on p.role_id = g.role_id
                            where g.actor_id = cms_access_actor()
                              and g.ended_changeset_id is null
                              and g.effect = $$allow$$
                              and p.command = $$actor.list$$
                        );
                end
                SQL,
            access: true,
        );

        $connection->statement('alter table actor_profiles enable row level security');
        $connection->statement('alter table actor_profiles force row level security');
        $connection->statement('create policy actor_profiles_own on actor_profiles for select using (actor_id = cms_access_actor())');
        $connection->statement('create policy actor_profiles_listed on actor_profiles for select using (cms_access_profile_listed(actor_id))');
        $connection->statement('create policy actor_profiles_owner_write on actor_profiles for all to current_user using (true) with check (true)');
        new TablePrivileges($connection)->limitTo('actor_profiles', [TablePrivilege::Select]);

        $this->definer(
            'cms_identity_register_actor(p_actor uuid, p_version bigint, p_class text, p_responsible uuid, p_display_name text, p_email text, p_at timestamptz, p_changeset uuid) returns bigint language plpgsql volatile',
            <<<'SQL'
                begin
                    if cms_access_actor() is null or not exists (
                        select 1 from changesets c
                        where c.changeset_id = p_changeset
                          and c.actor_id = cms_access_actor()
                          and c.command = $$actor.register$$
                          and c.xid = pg_current_xact_id()
                    ) then
                        raise exception using
                            errcode = $$42501$$,
                            message = $$cms_identity_register_actor runs only in the transaction of an actor.register changeset by the actor of the context$$;
                    end if;

                    if p_version <> 1 then
                        raise exception using errcode = $$22023$$, message = format($$an actor is registered at version 1, not %s$$, p_version);
                    end if;

                    if p_class = $$staff$$ and p_responsible is not null then
                        raise exception using errcode = $$22023$$, message = $$a staff actor has no responsible person$$;
                    elsif p_class = $$service$$ and not exists (
                        select 1 from actors r where r.id = p_responsible and r.actor_class = $$staff$$ and r.state = $$active$$
                    ) then
                        raise exception using errcode = $$22023$$, message = $$a service actor is registered with an active staff actor responsible for it$$;
                    elsif p_class not in ($$staff$$, $$service$$) then
                        raise exception using errcode = $$22023$$, message = format($$actor.register registers staff and service actors, not %s$$, p_class);
                    end if;

                    insert into actors (id, actor_class, state, version, credential_generation, created_at, responsible_actor_id)
                    values (p_actor, p_class, $$pending$$, p_version, 1, p_at, p_responsible);

                    insert into actor_profiles (actor_id, display_name, email, version)
                    values (p_actor, p_display_name, p_email, 1);

                    return 1;
                end
                SQL,
        );

        $this->definer(
            'cms_identity_activate_actor(p_actor uuid, p_version bigint, p_changeset uuid) returns void language plpgsql volatile',
            <<<'SQL'
                begin
                    if cms_access_actor() is null or not exists (
                        select 1 from changesets c
                        where c.changeset_id = p_changeset
                          and c.actor_id = cms_access_actor()
                          and c.command = $$actor.activate$$
                          and c.xid = pg_current_xact_id()
                    ) then
                        raise exception using
                            errcode = $$42501$$,
                            message = $$cms_identity_activate_actor runs only in the transaction of an actor.activate changeset by the actor of the context$$;
                    end if;

                    update actors a
                       set state = $$active$$,
                           version = p_version
                     where a.id = p_actor
                       and a.version = p_version - 1
                       and a.state = $$pending$$;

                    if not found then
                        raise exception using
                            errcode = $$P0002$$,
                            message = format($$no pending actor %s is at version %s$$, p_actor, p_version - 1);
                    end if;
                end
                SQL,
        );
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->statement('drop function cms_identity_activate_actor(uuid, bigint, uuid)');
        $connection->statement('drop function cms_identity_register_actor(uuid, bigint, text, uuid, text, text, timestamptz, uuid)');
        $connection->statement('drop table actor_profiles');
        $connection->statement('drop function cms_access_profile_listed(uuid)');
        $connection->statement('drop index actors_responsible_actor_id');
        $connection->statement('alter table actors drop column responsible_actor_id');
    }

    /**
     * A function that runs as the owner role, with the current schema as its fixed search_path, and
     * for an access function that a policy calls plan_cache_mode = auto, as the access functions
     * have it. The body is a literal of the format() that creates it, so its quotes and percent
     * signs are doubled.
     */
    private function definer(string $signature, string $body, bool $access = false): void
    {
        DB::connection($this->getConnection())->statement(sprintf(<<<'SQL'
            do $do$ begin
                execute format(
                    'create or replace function %s security definer set search_path = %%I, pg_temp%s as $body$ %s $body$',
                    current_schema()
                );
            end $do$
            SQL,
            $signature,
            $access ? ' set plan_cache_mode = auto' : '',
            str_replace(["'", '%'], ["''", '%%'], $body),
        ));
    }
};
