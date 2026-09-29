<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * What the commit (PRD 6.2 phase 7) needs from the schema, added by the owner role.
 *
 * `cms_identity_lock_actor(uuid, boolean)` locks one actor's row for the rest of the caller's
 * transaction and returns its version, or null when no actor has the id. The commit checks the
 * version of every aggregate the command read under a row lock, the actor and its on-behalf-of
 * chain included (invariants 11 and 37), but the app role reads no identity row itself: the access
 * migration gave it only the lookups, which run as the owner role. This function is one more such
 * lookup, SECURITY DEFINER with a fixed search_path, and it returns the version alone. It takes
 * FOR SHARE when the changeset only read the actor, so any number of commands by one actor commit
 * side by side while a change of the actor waits for them, and FOR NO KEY UPDATE when the changeset
 * changes the actor, which does not block the foreign keys that name it.
 *
 * `audit.aggregates` held only `<kind>:<uuid>` keys, but a variant of an entry is an aggregate too,
 * whose key is `variant:<uuid>:<variant>` (VariantRef::aggregateKey()), with the variant `shared`
 * or a locale as the variant heads have it. The CHECK now takes that optional last part, and still
 * nothing but ids and variant keys, so the audit keeps no text content (invariant 10). The
 * constraint on a partitioned table covers its partitions.
 */
return new class extends Migration
{
    /** An aggregate key: a kind, a UUID and, for a variant, `shared` or a locale. */
    private const string AGGREGATE = '[a-z][a-z0-9_]*:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(:(shared|[a-z]{2,3}(-[A-Za-z0-9]{2,8})*))?';

    /** The key as the access migration had it. */
    private const string UUID_KEY = '[a-z][a-z0-9_]*:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->statement(<<<'SQL'
            do $do$ begin
                execute format(
                    'create or replace function cms_identity_lock_actor(p_id uuid, p_for_update boolean) returns bigint language plpgsql volatile security definer set search_path = %I, pg_temp as $body$
                    declare
                        v_version bigint;
                    begin
                        if p_for_update then
                            select version into v_version from actors where id = p_id for no key update;
                        else
                            select version into v_version from actors where id = p_id for share;
                        end if;

                        return v_version;
                    end
                    $body$',
                    current_schema()
                );
            end $do$
            SQL);

        $this->aggregates(self::AGGREGATE);
    }

    public function down(): void
    {
        $this->aggregates(self::UUID_KEY);
        DB::connection($this->getConnection())->statement('drop function cms_identity_lock_actor(uuid, boolean)');
    }

    private function aggregates(string $key): void
    {
        DB::connection($this->getConnection())->statement(<<<SQL
            alter table audit drop constraint audit_aggregates, add constraint audit_aggregates check (
                cardinality(aggregates) >= 1
                and array_position(aggregates, null) is null
                and array_to_string(aggregates, ' ') ~ '^{$key}( {$key})*$'
                and cardinality(regexp_split_to_array(array_to_string(aggregates, ' '), ' ')) = cardinality(aggregates)
            )
            SQL);
    }
};
