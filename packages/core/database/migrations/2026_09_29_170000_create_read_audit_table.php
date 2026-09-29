<?php

declare(strict_types=1);

use Cbox\Cms\Core\Database\Domain\TablePrivilege;
use Cbox\Cms\Core\Database\Infrastructure\TablePrivileges;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The read audit (PRD 12.2, 12.12), created by the owner role: which actor read which fields of
 * which entries, written by the query pipeline synchronously in the read transaction, so the audit
 * commits with the answer.
 *
 * `read_audit` has one row per entry a read returned fields of that require the audit: the read's
 * id, a UUIDv7 shared by the rows of one read, the entry, the actor, the query and its version, the
 * highest classification among the fields, the fields by the address code gives them (`<handle>`
 * or `ext.<namespace>.<handle>`) and the read's position, the xmin of its snapshot. It has no text
 * content (invariant 10): every column is an id, a code or an enum, held to its form by a CHECK,
 * and it names the fields, never their values. The entry has no foreign key, because the audit
 * outlives the entry's physical deletion (PRD 12.4). It is partitioned by RANGE on read_id,
 * managed per day by the partition manager and never dropped, like `audit`.
 *
 * Row level security is enabled and forced. The app role may insert only rows whose actor is the
 * actor of the context (cms_access_actor(), from the access migration), and reads none: access to
 * the audit comes with its own audited read (PRD 12.12, B6).
 */
return new class extends Migration
{
    /** The five classes of PRD 12.2. */
    private const string CLASSES = "'public', 'internal', 'confidential', 'personal', 'sensitive'";

    /** A command or query name, as Command::NAME_PATTERN. */
    private const string QUERY = '^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$';

    /** A field address, as FieldDefinition::address() gives it. */
    private const string FIELD = '(ext\.[a-z][a-z0-9]{0,19}\.)?[a-z][a-z0-9]*(_[a-z0-9]+)*';

    public function up(): void
    {
        $connection = DB::connection($this->getConnection());
        $classes = self::CLASSES;
        $query = self::QUERY;
        $field = self::FIELD;

        $connection->statement(<<<SQL
            create table read_audit (
                read_id uuid not null,
                entry_id uuid not null,
                actor_id uuid not null references actors (id),
                query text not null,
                query_version integer not null,
                classification text not null,
                fields text[] not null,
                read_position xid8 not null,
                created_at timestamptz not null,
                constraint read_audit_pkey primary key (read_id, entry_id),
                constraint read_audit_query check (query ~ '{$query}' and length(query) <= 255),
                constraint read_audit_query_version check (query_version >= 1),
                constraint read_audit_classification check (classification in ({$classes})),
                constraint read_audit_fields check (
                    cardinality(fields) >= 1
                    and array_position(fields, null) is null
                    and array_to_string(fields, ' ') ~ '^{$field}( {$field})*$'
                    and cardinality(regexp_split_to_array(array_to_string(fields, ' '), ' ')) = cardinality(fields)
                )
            ) partition by range (read_id)
            SQL);
        $connection->statement('create index read_audit_actor_id on read_audit (actor_id)');
        $connection->statement('alter table read_audit enable row level security');
        $connection->statement('alter table read_audit force row level security');
        $connection->statement('create policy read_audit_write on read_audit for insert with check (actor_id = cms_access_actor())');

        new TablePrivileges($connection)->limitTo('read_audit', [TablePrivilege::Select, TablePrivilege::Insert]);
    }

    public function down(): void
    {
        DB::connection($this->getConnection())->statement('drop table read_audit');
    }
};
