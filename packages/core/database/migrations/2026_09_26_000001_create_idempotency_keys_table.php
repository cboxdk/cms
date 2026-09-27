<?php

declare(strict_types=1);

use Cbox\Cms\Core\Database\Domain\TablePrivilege;
use Cbox\Cms\Core\Database\Infrastructure\TablePrivileges;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The idempotency store's table (PRD 6.1, 4, 4.1), created by the owner role.
 *
 * `idempotency_keys` holds one record per completed claim: the scope (principal kind, principal,
 * command type), the key, the content hash and the changeset. lock_key is the claim's advisory
 * lock key (ClaimLock), which the lookup's index leads with; the lookup also matches the full
 * scope and key, so a hash collision never mixes two records.
 *
 * The table is partitioned by RANGE on created_at, one partition per day, and a partition is
 * dropped a week after its day ends (cms.database.partitions.tables). A unique index on a
 * partitioned table must contain the partition key (PRD 4.1), so no index can keep one record per
 * key across days. The store keeps it with the claim's advisory lock instead, and there is no
 * primary key: nothing updates or deletes a record, whole partitions are dropped.
 *
 * idempotency_keys_window checks what the store's lookup window relies on: a record expires at
 * most 7 days after it was created, because created_at is never before the changeset's time and
 * expires_at is that time plus 7 days. So a live record is never in a partition older than 7 days.
 *
 * The index is created on the empty parent, before any partition exists; each partition the
 * partition manager attaches gets its own copy.
 *
 * The app role keeps only SELECT and INSERT.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->statement(<<<'SQL'
            create table idempotency_keys (
                lock_key bigint not null,
                principal_kind text not null,
                principal text not null,
                command_type text not null,
                idempotency_key text not null,
                content_hash text not null,
                changeset_id uuid not null,
                expires_at timestamptz not null,
                created_at timestamptz not null,
                constraint idempotency_keys_principal_kind check (principal_kind in ('actor', 'source')),
                constraint idempotency_keys_content_hash check (content_hash ~ '^[0-9a-f]{64}$'),
                constraint idempotency_keys_window check (expires_at <= created_at + interval '168 hours')
            ) partition by range (created_at)
            SQL);
        $connection->statement('create index idempotency_keys_lock_key on idempotency_keys (lock_key)');

        new TablePrivileges($connection)->limitTo('idempotency_keys', [TablePrivilege::Select, TablePrivilege::Insert]);
    }

    public function down(): void
    {
        DB::connection($this->getConnection())->statement('drop table idempotency_keys');
    }
};
