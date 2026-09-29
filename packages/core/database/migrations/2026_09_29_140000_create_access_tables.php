<?php

declare(strict_types=1);

use Cbox\Cms\Core\Database\Domain\TablePrivilege;
use Cbox\Cms\Core\Database\Infrastructure\TablePrivileges;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Access (PRD 5.10, 12.2, 12.12, 4.1), created by the owner role: roles, grants, the audit table,
 * the functions that read the actor context and the row level security policies over it.
 *
 * `roles` holds a role's handle and its classification ceiling (PRD 12.2), and `role_permissions`
 * the command names the role may run. `grants` holds the grants of PRD 5.10: an actor, a role, a
 * node and a locale set (null for every locale), allowing or denying. There is one grant per actor,
 * role and node. In M1 only the testkit's fixtures write roles and grants, as the owner role; the
 * grant commands come with the panel (B1). The app role may only read them: the grants of the
 * actor of the context, and every role once an actor context is set, so the kernel reads an actor's
 * grants under a context that names only the actor, compiles them into access regions and then
 * sets the full context (Access\Adapter\PostgresAccessResolver).
 *
 * `audit` holds one row per changeset (PRD 12.12, invariant 2), written in the command transaction:
 * the actor, the command and its version, the issuer kind and the surface, the reason code and the
 * legal basis as enums, the aggregates the changeset changed as `<kind>:<uuid>` keys, and the
 * commit position. It has no text content (invariant 10): every column is an id, a code or an enum,
 * held to its form by a CHECK; a reason's free text lives classified on the changeset. It is
 * partitioned by RANGE on changeset_id, managed per day by the partition manager and never
 * dropped. The chain that hashes it comes with the audit worker (B6).
 *
 * The actor context. The kernel sets it with SET LOCAL inside the transaction of every command and
 * read (Access\Infrastructure\ActorContext), so a pooler in transaction mode never carries it to
 * another client (PRD 5.10): `cbox_cms.principal` is `actor` or `anonymous`, `cbox_cms.actor` the
 * actor's id, `cbox_cms.access_allowed` and `cbox_cms.access_denied` the paths of the compiled
 * access regions and of their exceptions as ltree arrays, and `cbox_cms.classification` the
 * context's classification access. The functions below read it, and every one fails closed: a
 * missing or empty setting means no context, and no context reaches nothing. A node is reached when
 * the deepest path of the context's regions and exceptions above it, or at it, is a region's path:
 * a region can lie inside another region's exception, where a more specific allow sits below a
 * deny.
 *
 * The policies. Every table below has row level security enabled and forced (PRD 4.2), and every
 * policy tests the context, so without one the app role reads no row and writes none
 * (tests/Postgres/WalkingSkeleton/RlsWithoutActorContextTest.php). An actor reaches a node through
 * its regions (`nodes`); an entry through its home node, with EXISTS against `nodes`, or as its
 * owner (`cms_access_home`, which the generated type tables use too with cms_home_node and
 * cms_owner_actor, through TypeTableAccess); a variant head, a revision, a payload and a head
 * snapshot through its entry; a placement generation and a placement locale through the
 * placement's node (PRD 5.10: placement rights are evaluated on the placement's node); a placement
 * through its entry or a generation it reaches; and a reason text through a revision of its
 * changeset, or its own changeset, and only up to the context's classification access. An actor
 * also reads the nodes its own grants name, so the kernel can read their paths before it has
 * compiled the regions. Every context, the anonymous one included, also reads what is public: the
 * released stage of placements that are live, and the active entries, the released variant heads,
 * the published revisions and payloads and the head snapshots behind them (`cms_access_released`).
 * The anonymous context reads nothing else and writes nothing. The writes of an actor are held by
 * the same tests, as WITH CHECK, so a command cannot move a row out of its regions; which commands
 * an actor may run is the kernel's check against the roles' permissions, and RLS is the backstop
 * (PRD 5.10). A changeset, its principals and its audit row are written only by the context's
 * actor, and the changesets an actor wrote are the ones it reads. A policy names another table only
 * through a function, so each read plans the policies of its own table alone.
 *
 * The identity tables (M1-T11) had a read policy for every role, because the credential verifier
 * and the actor directory run before a context exists. That policy is replaced by three functions
 * that look up one credential by its hash, the chain of one credential and one actor by its id, as
 * the owner role (SECURITY DEFINER, with a fixed search_path), so the app role reads no identity row
 * without a context and can list none. Sites, site locales, node routes, mount overrides and reads
 * of the changeset register and the audit have no policy yet and stay closed until the commands
 * that need them add theirs.
 *
 * The functions are created with CREATE OR REPLACE, because migrate:fresh drops tables and leaves
 * functions that do not depend on one.
 */
return new class extends Migration
{
    /** @var list<string> the content tables whose policies this migration adds */
    private const array PROTECTED = [
        'nodes', 'entries', 'variant_heads', 'revisions', 'revision_payloads', 'head_snapshots',
        'placements', 'placement_generations', 'placement_locales', 'changeset_reason_texts',
        'changesets', 'changeset_register', 'changeset_principals', 'release_log',
    ];

    /** @var list<string> the identity tables of M1-T11 */
    private const array IDENTITY = ['actors', 'service_credentials', 'service_credential_delegations'];

    /** @var list<string> the tables this migration creates, in order */
    private const array TABLES = ['roles', 'role_permissions', 'grants', 'audit'];

    /** @var list<string> the functions this migration creates, with their arguments */
    private const array FUNCTIONS = [
        'cms_identity_delegations(uuid)',
        'cms_identity_credential(text)',
        'cms_identity_actor(uuid)',
        'cms_access_sees_changeset(uuid)',
        'cms_access_own_changeset(uuid)',
        'cms_access_placement_live(uuid)',
        'cms_access_placement_node(uuid)',
        'cms_access_revision_home(bigint, text)',
        'cms_access_sees_revision(bigint, text)',
        'cms_access_sees_published(uuid, text, bigint)',
        'cms_access_sees_head(uuid, text)',
        'cms_access_head_public(uuid, text)',
        'cms_access_released(uuid, text)',
        'cms_access_entry(uuid)',
        'cms_access_granted(uuid)',
        'cms_access_home(uuid, uuid)',
        'cms_access_node(uuid)',
        'cms_access_classification_allows(text)',
        'cms_access_reaches(ltree)',
        'cms_access_actor()',
        'cms_access_context()',
    ];

    /** A locale, as in `site_locales`. */
    private const string LOCALE = '[a-z]{2,3}(-[A-Za-z0-9]{2,8})*';

    /** The five classes of PRD 12.2, in order. */
    private const string CLASSES = "'public', 'internal', 'confidential', 'personal', 'sensitive'";

    /** A command name, as Command::NAME_PATTERN. */
    private const string COMMAND = '^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$';

    /** An aggregate key, as AggregateRef::aggregateKey() gives it. */
    private const string AGGREGATE = '[a-z][a-z0-9_]*:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        $this->createTables();
        $this->createContextFunctions();
        $this->createIdentityLookups();

        foreach ([...self::TABLES, ...self::PROTECTED] as $table) {
            $connection->statement(sprintf('alter table %s enable row level security', $table));
            $connection->statement(sprintf('alter table %s force row level security', $table));
        }

        $this->createPolicies();

        $privileges = new TablePrivileges($connection);

        foreach (['roles', 'role_permissions', 'grants'] as $table) {
            $privileges->limitTo($table, [TablePrivilege::Select]);
        }

        $privileges->limitTo('audit', [TablePrivilege::Select, TablePrivilege::Insert]);
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());

        foreach (array_reverse(self::PROTECTED) as $table) {
            foreach ($this->policies($table) as $policy) {
                $connection->statement(sprintf('drop policy %s on %s', $policy, $table));
            }
        }

        foreach (self::IDENTITY as $table) {
            $connection->statement(sprintf('create policy %1$s_read on %1$s for select using (true)', $table));
        }

        $connection->statement('drop table '.implode(', ', array_reverse(self::TABLES)));
        $connection->statement('drop function '.implode(', ', self::FUNCTIONS));
    }

    private function createTables(): void
    {
        $connection = DB::connection($this->getConnection());
        $locale = self::LOCALE;
        $classes = self::CLASSES;
        $command = self::COMMAND;
        $aggregate = self::AGGREGATE;

        $connection->statement(<<<SQL
            create table roles (
                id uuid primary key,
                handle text not null,
                classification_ceiling text not null,
                version bigint not null,
                created_at timestamptz not null,
                constraint roles_handle_key unique (handle),
                constraint roles_handle check (handle ~ '^[a-z][a-z0-9_]{0,62}$'),
                constraint roles_classification_ceiling check (classification_ceiling in ({$classes})),
                constraint roles_version check (version >= 1)
            )
            SQL);

        $connection->statement(<<<SQL
            create table role_permissions (
                role_id uuid not null references roles (id),
                command text not null,
                created_at timestamptz not null,
                primary key (role_id, command),
                constraint role_permissions_command check (command ~ '{$command}' and length(command) <= 255)
            )
            SQL);

        $connection->statement(<<<SQL
            create table grants (
                id uuid primary key,
                actor_id uuid not null references actors (id),
                role_id uuid not null references roles (id),
                node_id uuid not null references nodes (id),
                effect text not null,
                locales text[],
                version bigint not null,
                created_at timestamptz not null,
                constraint grants_actor_role_node_key unique (actor_id, role_id, node_id),
                constraint grants_effect check (effect in ('allow', 'deny')),
                constraint grants_locales check (
                    cardinality(locales) >= 1
                    and array_position(locales, null) is null
                    and array_to_string(locales, ' ') ~ '^{$locale}( {$locale})*$'
                    and cardinality(regexp_split_to_array(array_to_string(locales, ' '), ' ')) = cardinality(locales)
                ),
                constraint grants_version check (version >= 1)
            )
            SQL);
        $connection->statement('create index grants_role_id on grants (role_id)');
        $connection->statement('create index grants_node_id on grants (node_id)');

        $connection->statement(<<<SQL
            create table audit (
                changeset_id uuid not null references changeset_register (changeset_id),
                actor_id uuid not null references actors (id),
                command text not null,
                command_version integer not null,
                issuer_kind text not null,
                surface text not null,
                reason_code text,
                legal_basis text,
                aggregates text[] not null,
                xid xid8 not null default pg_current_xact_id(),
                created_at timestamptz not null,
                constraint audit_pkey primary key (changeset_id),
                constraint audit_command check (command ~ '{$command}' and length(command) <= 255),
                constraint audit_command_version check (command_version >= 1),
                constraint audit_issuer_kind check (issuer_kind in ('human', 'agent', 'seed', 'migration', 'scheduler', 'sync', 'system')),
                constraint audit_surface check (surface in ('rest', 'inertia', 'mcp', 'cli', 'job', 'scheduler', 'subscriber', 'sidecar', 'seed')),
                constraint audit_reason_code check (reason_code ~ '^[a-z][a-z0-9]*(_[a-z0-9]+)*$' and length(reason_code) <= 63),
                constraint audit_legal_basis check (legal_basis in ('consent', 'contract', 'legal_obligation', 'vital_interests', 'public_task', 'legitimate_interests')),
                constraint audit_aggregates check (
                    cardinality(aggregates) >= 1
                    and array_position(aggregates, null) is null
                    and array_to_string(aggregates, ' ') ~ '^{$aggregate}( {$aggregate})*$'
                    and cardinality(regexp_split_to_array(array_to_string(aggregates, ' '), ' ')) = cardinality(aggregates)
                )
            ) partition by range (changeset_id)
            SQL);
        $connection->statement('create index audit_actor_id on audit (actor_id)');
    }

    /**
     * The functions over the actor context. They run as the caller (SECURITY INVOKER), so the
     * tables they read keep their own row level security.
     *
     * A policy refers to another table only through one of the PL/pgSQL functions, never with a
     * subquery of its own: Postgres plans a function's queries apart from the query that calls it,
     * so the policies of the table it reads are not expanded into every plan that reads this one.
     * Expanded inline, the policies of one table pull in those of the tables they name, and theirs
     * in turn, and the plan of a read of the payloads grew to seconds.
     */
    private function createContextFunctions(): void
    {
        $connection = DB::connection($this->getConnection());
        $classes = self::CLASSES;
        $live = "stage = 'released' and visibility = 'live'";

        foreach ([
            'cms_access_context() returns text' => <<<'SQL'
                select case current_setting('cbox_cms.principal', true)
                    when 'actor' then 'actor'
                    when 'anonymous' then 'anonymous'
                end
                SQL,
            'cms_access_actor() returns uuid' => <<<'SQL'
                select case when current_setting('cbox_cms.principal', true) = 'actor'
                    then nullif(current_setting('cbox_cms.actor', true), '')::uuid
                end
                SQL,
            'cms_access_reaches(p_path ltree) returns boolean' => <<<'SQL'
                select cms_access_actor() is not null and exists (
                    select 1
                    from unnest(coalesce(nullif(current_setting('cbox_cms.access_allowed', true), ''), '{}')::ltree[]) as a (path)
                    where a.path @> p_path
                      and not exists (
                          select 1
                          from unnest(coalesce(nullif(current_setting('cbox_cms.access_denied', true), ''), '{}')::ltree[]) as d (path)
                          where a.path @> d.path and d.path @> p_path
                      )
                )
                SQL,
            'cms_access_classification_allows(p_classification text) returns boolean' => <<<SQL
                select cms_access_context() is not null and coalesce(
                    array_position(array[{$classes}], p_classification)
                        <= array_position(array[{$classes}], nullif(current_setting('cbox_cms.classification', true), '')),
                    false
                )
                SQL,
        ] as $signature => $body) {
            $connection->statement(sprintf("create or replace function %s language sql stable as \$f\$\n%s\n\$f\$", $signature, $body));
        }

        $lookups = [
            'cms_access_node(p_node uuid)' => 'select 1 from nodes n where n.id = p_node and cms_access_reaches(n.path)',
            'cms_access_granted(p_node uuid)' => 'select 1 from grants g where g.node_id = p_node and g.actor_id = cms_access_actor()',
            'cms_access_entry(p_entry uuid)' => 'select 1 from entries e where e.id = p_entry and cms_access_home(e.home_node_id, e.owner_actor_id)',
            'cms_access_released(p_entry uuid, p_stage text)' => "select 1 from placement_locales pl where cms_access_context() is not null and p_stage = 'released' and pl.entry_id = p_entry and pl.{$live}",
            'cms_access_head_public(p_entry uuid, p_variant text)' => "select 1 from entries e where cms_access_context() is not null and e.id = p_entry and e.lifecycle = 'active' and exists (select 1 from placement_locales pl where pl.entry_id = p_entry and pl.{$live} and (p_variant = 'shared' or pl.locale = p_variant))",
            'cms_access_sees_head(p_entry uuid, p_variant text)' => 'select 1 from variant_heads h where h.entry_id = p_entry and h.variant = p_variant',
            'cms_access_sees_published(p_entry uuid, p_variant text, p_revision bigint)' => "select 1 from variant_heads h where h.entry_id = p_entry and h.variant = p_variant and h.published_revision_id = p_revision and h.release_state = 'released'",
            'cms_access_sees_revision(p_revision bigint, p_kind text)' => 'select 1 from revisions r where r.revision_id = p_revision and r.kind = p_kind',
            'cms_access_revision_home(p_revision bigint, p_kind text)' => 'select 1 from revisions r where r.revision_id = p_revision and r.kind = p_kind and cms_access_entry(r.entry_id)',
            'cms_access_placement_node(p_placement uuid)' => 'select 1 from placement_generations g where g.placement_id = p_placement and cms_access_node(g.node_id)',
            'cms_access_placement_live(p_placement uuid)' => "select 1 from placement_locales pl where cms_access_context() is not null and pl.placement_id = p_placement and pl.{$live}",
            'cms_access_own_changeset(p_changeset uuid)' => 'select 1 from changesets c where c.changeset_id = p_changeset and c.actor_id = cms_access_actor()',
            'cms_access_sees_changeset(p_changeset uuid)' => 'select 1 from revisions r where r.changeset_id = p_changeset',
        ];

        // cms_access_home calls cms_access_node, and cms_access_entry calls cms_access_home.
        $connection->statement(sprintf(
            'create or replace function %s returns boolean language plpgsql stable as $f$ begin return exists (%s); end $f$',
            'cms_access_node(p_node uuid)',
            $lookups['cms_access_node(p_node uuid)'],
        ));
        $connection->statement(<<<'SQL'
            create or replace function cms_access_home(p_home_node uuid, p_owner_actor uuid) returns boolean language sql stable as $f$
                select cms_access_actor() is not null and (p_owner_actor = cms_access_actor() or cms_access_node(p_home_node))
            $f$
            SQL);

        unset($lookups['cms_access_node(p_node uuid)']);

        foreach ($lookups as $signature => $query) {
            $connection->statement(sprintf(
                'create or replace function %s returns boolean language plpgsql stable as $f$ begin return exists (%s); end $f$',
                $signature,
                $query,
            ));
        }
    }

    /**
     * The lookups of the credential verifier and the actor directory: one row by its key, as the
     * owner role, whose policy on the identity tables lets it read.
     */
    private function createIdentityLookups(): void
    {
        $connection = DB::connection($this->getConnection());

        foreach ([
            'cms_identity_actor(p_id uuid) returns setof actors' => 'select * from actors where id = p_id',
            'cms_identity_credential(p_hash text) returns setof service_credentials' => 'select * from service_credentials where secret_hash = p_hash',
            'cms_identity_delegations(p_credential uuid) returns setof service_credential_delegations' => 'select * from service_credential_delegations where credential_id = p_credential order by position',
        ] as $signature => $body) {
            $connection->statement(sprintf(<<<'SQL'
                do $do$ begin
                    execute format(
                        'create or replace function %s language sql stable security definer set search_path = %%I, pg_temp as $body$ %s $body$',
                        current_schema()
                    );
                end $do$
                SQL, $signature, $body));
        }

        foreach (self::IDENTITY as $table) {
            $connection->statement(sprintf('drop policy %1$s_read on %1$s', $table));
        }
    }

    private function createPolicies(): void
    {
        $connection = DB::connection($this->getConnection());
        $actor = 'cms_access_actor() is not null';

        $policies = [
            'nodes' => [
                'actor' => ['all', 'cms_access_reaches(path)'],
                'granted' => ['select', 'cms_access_granted(id)'],
            ],
            'entries' => [
                'actor' => ['all', 'cms_access_home(home_node_id, owner_actor_id)'],
                'released' => ['select', "lifecycle = 'active' and cms_access_released(id, 'released')"],
            ],
            'variant_heads' => [
                'actor' => ['all', 'cms_access_entry(entry_id)'],
                'released' => ['select', "release_state = 'released' and cms_access_head_public(entry_id, variant)"],
            ],
            'revisions' => [
                'actor' => ['all', 'cms_access_entry(entry_id)'],
                'released' => ['select', "kind = 'published' and cms_access_sees_published(entry_id, variant, revision_id)"],
            ],
            'revision_payloads' => [
                'actor' => ['all', 'cms_access_revision_home(revision_id, kind)'],
                'released' => ['select', "kind = 'published' and cms_access_sees_revision(revision_id, kind)"],
            ],
            'head_snapshots' => [
                'actor' => ['all', 'cms_access_entry(entry_id)'],
                'released' => ['select', 'cms_access_sees_head(entry_id, variant)'],
            ],
            'placements' => [
                'actor' => ['all', "{$actor} and (cms_access_entry(entry_id) or cms_access_placement_node(id))"],
                'write' => ['insert', "{$actor} and (cms_access_entry(entry_id) or cms_access_released(entry_id, 'released'))"],
                'released' => ['select', 'cms_access_placement_live(id)'],
            ],
            'placement_generations' => [
                'actor' => ['all', 'cms_access_node(node_id)'],
                'released' => ['select', "stage = 'released' and cms_access_placement_live(placement_id)"],
            ],
            'placement_locales' => [
                'actor' => ['all', 'cms_access_node(node_id)'],
                'released' => ['select', "stage = 'released' and visibility = 'live' and cms_access_context() is not null"],
            ],
            'changesets' => [
                'actor' => ['select', 'actor_id = cms_access_actor()'],
                'write' => ['insert', 'actor_id = cms_access_actor()'],
            ],
            'changeset_register' => [
                'write' => ['insert', $actor],
            ],
            'changeset_principals' => [
                'actor' => ['select', 'cms_access_own_changeset(changeset_id)'],
                'write' => ['insert', 'cms_access_own_changeset(changeset_id)'],
            ],
            'changeset_reason_texts' => [
                'actor' => ['select', "{$actor} and cms_access_classification_allows(classification) and (cms_access_sees_changeset(changeset_id) or cms_access_own_changeset(changeset_id))"],
                'write' => ['insert', 'cms_access_own_changeset(changeset_id)'],
            ],
            'release_log' => [
                'actor' => ['all', 'cms_access_entry(entry_id)'],
            ],
            'roles' => [
                'read' => ['select', $actor],
            ],
            'role_permissions' => [
                'read' => ['select', $actor],
            ],
            'grants' => [
                'read' => ['select', 'actor_id = cms_access_actor()'],
            ],
            'audit' => [
                'write' => ['insert', 'actor_id = cms_access_actor()'],
            ],
        ];

        foreach ($policies as $table => $byName) {
            foreach ($byName as $name => [$command, $test]) {
                $connection->statement(match ($command) {
                    'all' => sprintf('create policy %1$s_%2$s on %1$s for all using (%3$s) with check (%3$s)', $table, $name, $test),
                    'insert' => sprintf('create policy %1$s_%2$s on %1$s for insert with check (%3$s)', $table, $name, $test),
                    default => sprintf('create policy %1$s_%2$s on %1$s for select using (%3$s)', $table, $name, $test),
                });
            }
        }

        foreach (['roles', 'role_permissions', 'grants'] as $table) {
            $connection->statement(sprintf('create policy %1$s_owner_write on %1$s for all to current_user using (true) with check (true)', $table));
        }
    }

    /**
     * @return list<string>
     */
    private function policies(string $table): array
    {
        $names = [];

        foreach (DB::connection($this->getConnection())->select('select policyname::text as name from pg_policies where tablename = ? and schemaname = current_schema()', [$table]) as $row) {
            if (is_object($row) && property_exists($row, 'name') && is_string($row->name)) {
                $names[] = $row->name;
            }
        }

        return $names;
    }
};
