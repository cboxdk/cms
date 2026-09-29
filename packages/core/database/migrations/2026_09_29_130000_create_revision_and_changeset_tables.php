<?php

declare(strict_types=1);

use Cbox\Cms\Core\Database\Domain\TablePrivilege;
use Cbox\Cms\Core\Database\Infrastructure\TablePrivileges;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The storage form of PRD 4.1 for changesets, revisions, head snapshots and the release log
 * (PRD 5.4 to 5.6, 6.1), created by the owner role.
 *
 * Changesets (PRD 5.5, 6.1). `changeset_register` is the narrow, unpartitioned register of every
 * changeset: its id and its retention class. Its primary key makes a changeset id unique across the
 * retention classes, which the receipt tables, partitioned by class first, cannot (M0-T13), and it
 * is the target of the foreign keys from the unpartitioned tables that name a changeset.
 * `changesets` holds each changeset's metadata and is partitioned by RANGE on changeset_id, managed
 * per day by the partition manager and never dropped (PRD 4: retention as the revisions'). The
 * metadata is the command's name and version; the actor, the issuer kind and the surface; the
 * reason code; the idempotency key and the correlation id; the provenance of an agent's or an
 * ingestion's content: the model and its version, its parameters as a JSON object, the reference
 * to the prompt and the sources as a JSON array; the format version of the stored form (PRD 3.3);
 * and the commit position, the writing transaction's id from pg_current_xact_id(), a column default
 * so no writer can give another, the same position the event log orders by (PRD 7.4).
 * `changeset_principals` is the on-behalf-of chain in order, one row per principal, partitioned
 * and managed as `changesets` is. The reason's free text can name people, so it is classified
 * content (PRD 6.1, 12.12): it lives in `changeset_reason_texts` with its classification, apart
 * from the metadata, and never in the audit chain or an event.
 *
 * Revisions (PRD 4.1, 5.4). `revisions` is the narrow, unpartitioned key register: revision_id
 * from the sequence revisions_revision_id_seq, so it correlates with time, the entry, the variant
 * (`shared` or a locale), rev_no, the kind (`draft` or `published`), the schema version, the
 * changeset and the time. It holds the unique key (entry_id, variant, rev_no) and is the target of
 * the foreign keys to a revision: the variant heads' draft and published revision and the release
 * log's. `revision_payloads` holds the content, partitioned by LIST on kind and then by RANGE on
 * revision_id, so a partition of published revisions is append-only and drafts are thinned by
 * writing the survivors to a new partition and swapping, never by DELETE. Postgres 17 cannot keep a
 * key unique across partitions that does not hold the partition key, so the payloads have no
 * foreign key; their key (revision_id, kind) lives in the register. The two kinds are managed by
 * the partition manager as `revision_payloads_draft` and `revision_payloads_published`, on the
 * shared sequence, and never dropped.
 *
 * `head_snapshots` holds the current content of a head of a type whose history is audit-only or
 * none (PRD 4.1), which has no revisions to rebuild from: one row per variant head, written in the
 * transaction of the type row and updated in place, so it has fillfactor 80 and a low vacuum
 * threshold and no index on a column a save changes (PRD 4.2).
 *
 * `release_log` records each release and withdrawal of a variant (PRD 5.6): the revision released,
 * or none for a withdrawal, the time it took effect and the changeset. Its index on (entry_id,
 * variant, effective_at) answers which revision was public at a given time.
 *
 * Every table has row level security, forced so it holds for the owner too (PRD 4.2), and no policy
 * yet, so it is closed to every role but a superuser until the policies over the actor context come
 * (PRD 5.10). The partition manager gives each new partition its parent's flags and grants. The
 * app role keeps SELECT and INSERT on every table and UPDATE on head_snapshots alone: a changeset,
 * a revision, a payload and a release are never changed once written.
 */
return new class extends Migration
{
    /** @var list<string> the tables whose row security and grants this migration sets, parents before their partitions */
    private const array TABLES = [
        'changeset_register',
        'changesets',
        'changeset_principals',
        'changeset_reason_texts',
        'revisions',
        'revision_payloads',
        'revision_payloads_draft',
        'revision_payloads_published',
        'head_snapshots',
        'release_log',
    ];

    /** A locale: a BCP 47 language with optional subtags, as the variant heads have it. */
    private const string LOCALE = '^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$';

    public function up(): void
    {
        $connection = DB::connection($this->getConnection());
        $variant = sprintf("variant = 'shared' or variant ~ '%s'", self::LOCALE);

        $connection->statement(<<<'SQL'
            create table changeset_register (
                changeset_id uuid primary key,
                retention_class text not null,
                constraint changeset_register_retention_class check (retention_class in ('standard', 'evidence'))
            )
            SQL);

        $connection->statement(<<<'SQL'
            create table changesets (
                changeset_id uuid not null references changeset_register (changeset_id),
                command text not null,
                command_version integer not null,
                actor_id uuid not null references actors (id),
                issuer_kind text not null,
                surface text not null,
                reason_code text,
                idempotency_key text not null,
                correlation_id text not null,
                provenance_model text,
                provenance_model_version text,
                provenance_parameters jsonb,
                provenance_prompt text,
                provenance_sources jsonb,
                format_version integer not null,
                xid xid8 not null default pg_current_xact_id(),
                created_at timestamptz not null,
                constraint changesets_pkey primary key (changeset_id),
                constraint changesets_command check (command ~ '^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$' and length(command) <= 255),
                constraint changesets_command_version check (command_version >= 1),
                constraint changesets_issuer_kind check (issuer_kind in ('human', 'agent', 'seed', 'migration', 'scheduler', 'sync', 'system')),
                constraint changesets_surface check (surface in ('rest', 'inertia', 'mcp', 'cli', 'job', 'scheduler', 'subscriber', 'sidecar', 'seed')),
                constraint changesets_reason_code check (reason_code ~ '^[a-z][a-z0-9]*(_[a-z0-9]+)*$' and length(reason_code) <= 63),
                constraint changesets_idempotency_key check (idempotency_key ~ '^[!-~]{1,255}$'),
                constraint changesets_correlation_id check (correlation_id ~ '^[!-~]{1,128}$'),
                constraint changesets_provenance_model check ((provenance_model is null) = (provenance_model_version is null)),
                constraint changesets_provenance_needs_model check (provenance_model is not null or (provenance_parameters is null and provenance_prompt is null)),
                constraint changesets_provenance_parameters check (jsonb_typeof(provenance_parameters) = 'object'),
                constraint changesets_provenance_sources check (jsonb_typeof(provenance_sources) = 'array'),
                constraint changesets_format_version check (format_version >= 1)
            ) partition by range (changeset_id)
            SQL);
        $connection->statement('create index changesets_actor_id on changesets (actor_id)');

        $connection->statement(<<<'SQL'
            create table changeset_principals (
                changeset_id uuid not null references changeset_register (changeset_id),
                position smallint not null,
                actor_id uuid not null references actors (id),
                constraint changeset_principals_pkey primary key (changeset_id, position),
                constraint changeset_principals_position check (position >= 1)
            ) partition by range (changeset_id)
            SQL);
        $connection->statement('create index changeset_principals_actor_id on changeset_principals (actor_id)');

        $connection->statement(<<<'SQL'
            create table changeset_reason_texts (
                changeset_id uuid primary key references changeset_register (changeset_id),
                classification text not null,
                text text not null,
                created_at timestamptz not null,
                constraint changeset_reason_texts_classification check (classification in ('public', 'internal', 'confidential', 'personal', 'sensitive')),
                constraint changeset_reason_texts_text check (btrim(text) <> '' and octet_length(text) <= 4000)
            )
            SQL);

        $connection->statement('create sequence revisions_revision_id_seq as bigint minvalue 1');
        $connection->statement(<<<SQL
            create table revisions (
                revision_id bigint primary key default nextval('revisions_revision_id_seq'),
                entry_id uuid not null references entries (id),
                variant text not null,
                rev_no integer not null,
                kind text not null,
                schema_version integer not null,
                changeset_id uuid not null references changeset_register (changeset_id),
                created_at timestamptz not null,
                constraint revisions_entry_variant_rev_no_key unique (entry_id, variant, rev_no),
                constraint revisions_variant check ({$variant}),
                constraint revisions_rev_no check (rev_no >= 1),
                constraint revisions_kind check (kind in ('draft', 'published')),
                constraint revisions_schema_version check (schema_version >= 1)
            )
            SQL);
        $connection->statement('alter sequence revisions_revision_id_seq owned by revisions.revision_id');
        $connection->statement('create index revisions_changeset_id on revisions (changeset_id)');

        $connection->statement(<<<'SQL'
            create table revision_payloads (
                revision_id bigint not null,
                kind text not null,
                format_version integer not null,
                content jsonb not null,
                constraint revision_payloads_pkey primary key (revision_id, kind),
                constraint revision_payloads_revision_id check (revision_id >= 1),
                constraint revision_payloads_format_version check (format_version >= 1),
                constraint revision_payloads_content check (jsonb_typeof(content) = 'object')
            ) partition by list (kind)
            SQL);
        $connection->statement("create table revision_payloads_draft partition of revision_payloads for values in ('draft') partition by range (revision_id)");
        $connection->statement("create table revision_payloads_published partition of revision_payloads for values in ('published') partition by range (revision_id)");

        $connection->statement('alter table variant_heads add constraint variant_heads_draft_revision_id_fkey foreign key (draft_revision_id) references revisions (revision_id)');
        $connection->statement('alter table variant_heads add constraint variant_heads_published_revision_id_fkey foreign key (published_revision_id) references revisions (revision_id)');
        $connection->statement('create index variant_heads_draft_revision_id on variant_heads (draft_revision_id)');
        $connection->statement('create index variant_heads_published_revision_id on variant_heads (published_revision_id)');

        $connection->statement(<<<'SQL'
            create table head_snapshots (
                entry_id uuid not null,
                variant text not null,
                schema_version integer not null,
                format_version integer not null,
                content jsonb not null,
                updated_at timestamptz not null,
                constraint head_snapshots_pkey primary key (entry_id, variant),
                constraint head_snapshots_head_fkey foreign key (entry_id, variant) references variant_heads (entry_id, variant),
                constraint head_snapshots_schema_version check (schema_version >= 1),
                constraint head_snapshots_format_version check (format_version >= 1),
                constraint head_snapshots_content check (jsonb_typeof(content) = 'object')
            ) with (fillfactor = 80, autovacuum_vacuum_scale_factor = 0.01)
            SQL);

        $connection->statement('create sequence release_log_release_id_seq as bigint minvalue 1');
        $connection->statement(<<<'SQL'
            create table release_log (
                release_id bigint primary key default nextval('release_log_release_id_seq'),
                entry_id uuid not null,
                variant text not null,
                action text not null,
                revision_id bigint references revisions (revision_id),
                effective_at timestamptz not null,
                changeset_id uuid not null references changeset_register (changeset_id),
                constraint release_log_head_fkey foreign key (entry_id, variant) references variant_heads (entry_id, variant),
                constraint release_log_action check (action in ('released', 'withdrawn')),
                constraint release_log_revision check ((action = 'released') = (revision_id is not null))
            )
            SQL);
        $connection->statement('alter sequence release_log_release_id_seq owned by release_log.release_id');
        $connection->statement('create index release_log_head_effective_at on release_log (entry_id, variant, effective_at)');
        $connection->statement('create index release_log_revision_id on release_log (revision_id)');
        $connection->statement('create index release_log_changeset_id on release_log (changeset_id)');

        foreach (self::TABLES as $table) {
            $connection->statement(sprintf('alter table %s enable row level security', $table));
            $connection->statement(sprintf('alter table %s force row level security', $table));
        }

        $privileges = new TablePrivileges($connection);

        foreach (['changeset_register', 'changesets', 'changeset_principals', 'changeset_reason_texts', 'revisions', 'revision_payloads', 'release_log'] as $table) {
            $privileges->limitTo($table, [TablePrivilege::Select, TablePrivilege::Insert]);
        }

        $privileges->limitTo('head_snapshots', [TablePrivilege::Select, TablePrivilege::Insert, TablePrivilege::Update]);
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->statement('drop table release_log, head_snapshots');
        $connection->statement('alter table variant_heads drop constraint variant_heads_published_revision_id_fkey, drop constraint variant_heads_draft_revision_id_fkey');
        $connection->statement('drop index variant_heads_published_revision_id, variant_heads_draft_revision_id');
        $connection->statement('drop table revision_payloads, revisions, changeset_reason_texts, changeset_principals, changesets, changeset_register');
    }
};
