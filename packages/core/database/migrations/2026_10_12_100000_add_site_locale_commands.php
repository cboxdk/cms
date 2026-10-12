<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * What site.add_locale, site.remove_locale and the full site reconcile of cms:sites:sync need from
 * the schema (PRD 5.9, 11.14, gap report G30), added by the owner role.
 *
 * Retiring a locale. `site_locales` gets `retired_at`: null while the site publishes in the locale,
 * the changeset's time once site.remove_locale retired it. The row stays, because `node_routes`
 * references it and the routes in the locale stay with it, so site.add_locale of a retired locale
 * reinstates the row and the routes come back with it. Every reader of a site's locales, the
 * lookups below, the placement reader and the route reader, takes only the rows that are not
 * retired.
 *
 * Writing the sites as the app role. Until now `sites` and `site_locales` were written only by the
 * owner role, by the testkit's structure fixtures and by the owner function
 * `cms_structure_register_site`, through the blanket policies `sites_owner_write` and
 * `site_locales_owner_write`, which let the owner write every row of the two tables and so narrowed
 * "forced row level security holds for the owner too" (PRD 4.2). The site commands replace them,
 * and the function is dropped: site.register, site.add_locale and site.remove_locale write the two
 * tables as the app role under the call's actor context, through policies that take a row of a
 * site when the actor's regions reach the site's root node (`cms_access_node(root_node_id)`,
 * `cms_access_site(site_id)`), as `nodes_actor` takes a node, or in the transaction of a changeset
 * of one of those commands by the actor of the context whose audit row names the site
 * (`cms_structure_site_changeset(site)`), the commit's own record of what the kernel authorized,
 * which is how the installation operator, which holds no grant, writes through cms:sites:sync:
 *
 * - `sites_write` takes a site's row, and `sites_update` lets the row's version move;
 * - `site_locales_write` takes a locale's row, and `site_locales_update` lets it retire and come
 *   back;
 * - `node_routes_site_root` now takes the route `/` in the same transactions, so the root route of
 *   an added locale is written as a site's registration writes its root routes; `nodes_site_root`
 *   is unchanged, because only a registration makes a root node.
 *
 * The owner keeps reading: `sites_owner_read` and `site_locales_owner_read`, SELECT for the owner
 * alone, take the place of the dropped policies for the lookups `cms_structure_site` and
 * `cms_structure_site_named`, which cms:sites:sync runs before any command and so without an actor
 * context; `sites_owner_lock`, an UPDATE policy for the owner whose check is false, lets
 * `cms_structure_lock_site` keep locking a site's row with SELECT ... FOR SHARE, which needs an
 * UPDATE policy that sees the row, and never change one; and `audit_owner_read` lets the owner
 * function read the audit row of the current transaction, which the app role never reads.
 *
 * `cms_structure_locale_use(site, locale)` counts, as the owner role, the released placement
 * locales in the locale below the site's root that are not withdrawn, past the actor's regions,
 * because a locale a placement still uses anywhere on the site may not be retired (site_locale_in_use),
 * whoever asks. It raises 42501 without an actor context.
 *
 * The functions are created with CREATE OR REPLACE, because migrate:fresh drops tables and leaves
 * functions that do not depend on one.
 */
return new class extends Migration
{
    /** @var list<string> the tables whose blanket owner write policy the site commands replace */
    private const array REPLACED = ['sites', 'site_locales'];

    /** @var list<string> the functions this migration creates, with their arguments */
    private const array FUNCTIONS = [
        'cms_structure_locale_use(uuid, text)',
        'cms_structure_site_changeset(uuid)',
        'cms_access_site(uuid)',
    ];

    /** @var list<string> the site commands whose changeset lets the app role write the site's rows */
    private const array SITE_COMMANDS = ['site.register', 'site.add_locale', 'site.remove_locale'];

    /** The columns the lookups return. */
    private const string SITE = 'table (id uuid, handle text, root_node_id uuid, version bigint, locales text)';

    /** The query the lookups run, with the condition on `s`, over the locales that are not retired. */
    private const string LOOKUP = <<<'SQL'
        begin
            return query
                select s.id, s.handle, s.root_node_id, s.version,
                       coalesce((select string_agg(l.locale, $$,$$ order by l.locale) from site_locales l where l.site_id = s.id and l.retired_at is null), $$$$)
                from sites s
                where %s;
        end
        SQL;

    /** The query the lookups ran before, over every locale. */
    private const string PREVIOUS_LOOKUP = <<<'SQL'
        begin
            return query
                select s.id, s.handle, s.root_node_id, s.version,
                       coalesce((select string_agg(l.locale, $$,$$ order by l.locale) from site_locales l where l.site_id = s.id), $$$$)
                from sites s
                where %s;
        end
        SQL;

    /** The check the locale use starts with. */
    private const string GUARD = <<<'SQL'
        if cms_access_actor() is null then
            raise exception using errcode = $$42501$$, message = $$cms_structure_locale_use runs only under an actor context$$;
        end if;
        SQL;

    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->statement('alter table site_locales add column retired_at timestamptz');
        $connection->statement(<<<'SQL'
            create or replace function cms_access_site(p_site uuid) returns boolean language plpgsql stable set plan_cache_mode = auto as $f$
                begin return exists (select 1 from sites s where s.id = p_site and cms_access_node(s.root_node_id)); end
            $f$
            SQL);

        $connection->statement('drop function cms_structure_register_site(uuid, text, uuid, text[], bigint, timestamptz, uuid)');

        foreach (self::REPLACED as $table) {
            $connection->statement(sprintf('drop policy %1$s_owner_write on %1$s', $table));
            $connection->statement(sprintf('create policy %1$s_owner_read on %1$s for select to current_user using (true)', $table));
        }

        $connection->statement('create policy audit_owner_read on audit for select to current_user using (true)');
        $connection->statement('create policy sites_owner_lock on sites for update to current_user using (true) with check (false)');

        $this->definer(
            'cms_structure_site_changeset(p_site uuid) returns boolean language sql stable',
            sprintf(<<<'SQL'
                select exists (
                    select 1 from changesets c
                    join audit a on a.changeset_id = c.changeset_id
                    where c.actor_id = cms_access_actor()
                      and c.xid = pg_current_xact_id()
                      and c.command in (%s)
                      and ($$site:$$ || p_site::text) = any (a.aggregates)
                )
                SQL, implode(', ', array_map(static fn (string $command): string => '$$'.$command.'$$', self::SITE_COMMANDS))),
        );

        $connection->statement('create policy sites_write on sites for insert with check (cms_access_node(root_node_id) or cms_structure_site_changeset(id))');
        $connection->statement('create policy sites_update on sites for update using (cms_access_node(root_node_id) or cms_structure_site_changeset(id)) with check (cms_access_node(root_node_id) or cms_structure_site_changeset(id))');
        $connection->statement('create policy site_locales_write on site_locales for insert with check (cms_access_site(site_id) or cms_structure_site_changeset(site_id))');
        $connection->statement('create policy site_locales_update on site_locales for update using (cms_access_site(site_id) or cms_structure_site_changeset(site_id)) with check (cms_access_site(site_id) or cms_structure_site_changeset(site_id))');
        $connection->statement('drop policy node_routes_site_root on node_routes');
        $connection->statement("create policy node_routes_site_root on node_routes for insert with check (route = '/' and cms_structure_site_changeset(site_id))");

        $this->definer('cms_structure_site(p_site uuid) returns '.self::SITE.' language plpgsql stable', sprintf(self::LOOKUP, 's.id = p_site'));
        $this->definer('cms_structure_site_named(p_handle text) returns '.self::SITE.' language plpgsql stable', sprintf(self::LOOKUP, 's.handle = p_handle'));

        $this->definer(
            'cms_structure_locale_use(p_site uuid, p_locale text) returns bigint language plpgsql stable',
            <<<'SQL'
                begin
                    %s
                    return (
                        select count(*)
                        from placement_locales pl
                        join nodes n on n.id = pl.node_id
                        where pl.stage = $$released$$
                          and pl.locale = p_locale
                          and pl.visibility <> $$withdrawn$$
                          and n.path <@ (select r.path from nodes r join sites s on s.root_node_id = r.id where s.id = p_site)
                    );
                end
                SQL,
            self::GUARD,
        );
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->statement('drop function '.implode(', ', self::FUNCTIONS));
        $this->definer('cms_structure_site(p_site uuid) returns '.self::SITE.' language plpgsql stable', sprintf(self::PREVIOUS_LOOKUP, 's.id = p_site'));
        $this->definer('cms_structure_site_named(p_handle text) returns '.self::SITE.' language plpgsql stable', sprintf(self::PREVIOUS_LOOKUP, 's.handle = p_handle'));

        $connection->statement('drop policy node_routes_site_root on node_routes');
        $connection->statement("create policy node_routes_site_root on node_routes for insert with check (route = '/' and cms_structure_registering())");
        $connection->statement('drop policy site_locales_update on site_locales');
        $connection->statement('drop policy site_locales_write on site_locales');
        $connection->statement('drop policy sites_update on sites');
        $connection->statement('drop policy sites_write on sites');
        $connection->statement('drop policy sites_owner_lock on sites');
        $connection->statement('drop policy audit_owner_read on audit');

        foreach (self::REPLACED as $table) {
            $connection->statement(sprintf('drop policy %1$s_owner_read on %1$s', $table));
            $connection->statement(sprintf('create policy %1$s_owner_write on %1$s for all to current_user using (true) with check (true)', $table));
        }

        $connection->statement('alter table site_locales drop column retired_at');
    }

    /**
     * A function that runs as the owner role, with the current schema as its fixed search_path. The
     * body is a literal of the format() that creates it, so its quotes and percent signs are doubled,
     * and the guard, when one is given, is put in before that.
     */
    private function definer(string $signature, string $body, ?string $guard = null): void
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
            str_replace(["'", '%'], ["''", '%%'], $guard === null ? $body : sprintf($body, $guard)),
        ));
    }
};
