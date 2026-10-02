<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * What site.register and cms:sites:sync need from the schema (PRD 5.8, 5.9, 11.14), added by the
 * owner role.
 *
 * `cms_structure_site(site)` and `cms_structure_site_named(handle)` read one site by its id or its
 * handle: its id, handle, root node and version, and its locales, sorted and joined by commas (a
 * locale holds no comma). They run as the owner role (SECURITY DEFINER, with a fixed search_path)
 * and need no actor context, so cms:sites:sync compares the configuration with the database before
 * any command runs. Every context reads `sites` and `site_locales` anyway (`sites_read`,
 * `site_locales_read`); the lookups give no more than that, one site at a time, never a list.
 *
 * `cms_structure_register_site(site, handle, root, locales, version, time, changeset)` writes a
 * registration as the owner role, which `nodes_owner_write`, `sites_owner_write`,
 * `site_locales_owner_write` and `node_routes_owner_write` let through the forced row level
 * security: the root node, a node of kind site at the top of the tree with its id as its one path
 * label, at version 1; the site at the version given, which is 1; a row of `site_locales` per
 * locale; and in each locale the route `/` to the root node, all at the time given. It runs only
 * where the command runs: under an actor context, in the transaction that wrote the changeset as a
 * site.register by the context's actor (the changeset's xid is the transaction's). A version other
 * than 1, no locale, a locale named twice, or a site, handle or node that exists raises and writes
 * nothing.
 *
 * The functions are created with CREATE OR REPLACE, because migrate:fresh drops tables and leaves
 * functions that do not depend on one.
 */
return new class extends Migration
{
    /** @var list<string> the functions this migration creates, with their arguments */
    private const array FUNCTIONS = [
        'cms_structure_register_site(uuid, text, uuid, text[], bigint, timestamptz, uuid)',
        'cms_structure_site_named(text)',
        'cms_structure_site(uuid)',
    ];

    /** The columns the lookups return. */
    private const string SITE = 'table (id uuid, handle text, root_node_id uuid, version bigint, locales text)';

    /** The query the lookups run, with the condition on `s`. */
    private const string LOOKUP = <<<'SQL'
        begin
            return query
                select s.id, s.handle, s.root_node_id, s.version,
                       coalesce((select string_agg(l.locale, $$,$$ order by l.locale) from site_locales l where l.site_id = s.id), $$$$)
                from sites s
                where %s;
        end
        SQL;

    public function up(): void
    {
        $this->definer('cms_structure_site(p_site uuid) returns '.self::SITE.' language plpgsql stable', sprintf(self::LOOKUP, 's.id = p_site'));
        $this->definer('cms_structure_site_named(p_handle text) returns '.self::SITE.' language plpgsql stable', sprintf(self::LOOKUP, 's.handle = p_handle'));

        $this->definer(
            'cms_structure_register_site(p_site uuid, p_handle text, p_root uuid, p_locales text[], p_version bigint, p_at timestamptz, p_changeset uuid) returns void language plpgsql volatile',
            <<<'SQL'
                begin
                    if cms_access_actor() is null or not exists (
                        select 1 from changesets c
                        where c.changeset_id = p_changeset
                          and c.actor_id = cms_access_actor()
                          and c.command = $$site.register$$
                          and c.xid = pg_current_xact_id()
                    ) then
                        raise exception using
                            errcode = $$42501$$,
                            message = $$cms_structure_register_site runs only in the transaction of a site.register changeset by the actor of the context$$;
                    end if;

                    if p_version <> 1 then
                        raise exception using errcode = $$22023$$, message = format($$a site is registered at version 1, not %s$$, p_version);
                    end if;

                    if coalesce(cardinality(p_locales), 0) = 0 then
                        raise exception using errcode = $$22023$$, message = $$a site is registered with at least one locale$$;
                    end if;

                    if (select count(distinct l) from unnest(p_locales) l) <> cardinality(p_locales) then
                        raise exception using errcode = $$22023$$, message = $$a site names each locale once$$;
                    end if;

                    insert into nodes (id, parent_id, kind, path, mount_source_id, version, created_at)
                    values (p_root, null, $$site$$, text2ltree(replace(p_root::text, $$-$$, $$$$)), null, 1, p_at);

                    insert into sites (id, handle, root_node_id, version, created_at)
                    values (p_site, p_handle, p_root, p_version, p_at);

                    insert into site_locales (site_id, locale, created_at)
                    select p_site, l, p_at from unnest(p_locales) l;

                    insert into node_routes (site_id, locale, route, node_id, created_at)
                    select p_site, l, $$/$$, p_root, p_at from unnest(p_locales) l;
                end
                SQL,
        );
    }

    public function down(): void
    {
        DB::connection($this->getConnection())->statement('drop function '.implode(', ', self::FUNCTIONS));
    }

    /**
     * A function that runs as the owner role, with the current schema as its fixed search_path. The
     * body is a literal of the format() that creates it, so its quotes and percent signs are doubled.
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
