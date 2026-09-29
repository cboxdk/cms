<?php

declare(strict_types=1);

use Cbox\Cms\Core\Database\Domain\TablePrivilege;
use Cbox\Cms\Core\Database\Infrastructure\TablePrivileges;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The identity tables (PRD 5.16, 6.4, 4.2), created by the owner role.
 *
 * `actors` holds every actor as an aggregate: its class, which never changes, its state, its
 * version, which counts every change, and its credential generation, which deactivation,
 * deprovisioning and actor.credentials_revoke count up. An actor is never deleted; a deprovisioned
 * actor stays as a subject.
 *
 * `service_credentials` holds the service credentials of service actors: 256 random bits and a
 * checksum on the wire, stored only as the SHA-256 of the token (secret_hash, unique), with the
 * actor's credential generation when it was issued, its issuer kind, its classification ceiling
 * and an expiry, which every credential has. An agent's ceiling is at most confidential (PRD 2.31).
 * `service_credential_delegations` holds the on-behalf-of chain of a credential, in order.
 *
 * Each table has row level security, forced so its policies hold for the owner too (PRD 4.2). The
 * read policy lets every role read every row: the credential verifier and the actor directory run
 * before an actor context exists, as the first phase of every command and read (PRD 6.2), and the
 * grants decide who reads at all. The write policy is for the role that runs this migration, the
 * owner; no other role has one, so writes fail closed until a command that changes an actor adds
 * its policy. The app role keeps only SELECT.
 */
return new class extends Migration
{
    private const array TABLES = ['actors', 'service_credentials', 'service_credential_delegations'];

    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->statement(<<<'SQL'
            create table actors (
                id uuid primary key,
                actor_class text not null,
                state text not null,
                version bigint not null,
                credential_generation bigint not null,
                created_at timestamptz not null,
                constraint actors_class check (actor_class in ('staff', 'end_user', 'service')),
                constraint actors_state check (state in ('pending', 'active', 'deactivated', 'deprovisioned')),
                constraint actors_version check (version >= 1),
                constraint actors_credential_generation check (credential_generation >= 1)
            )
            SQL);

        $connection->statement(<<<'SQL'
            create table service_credentials (
                id uuid primary key,
                actor_id uuid not null references actors (id),
                secret_hash text not null,
                credential_generation bigint not null,
                issuer_kind text not null,
                classification_ceiling text not null,
                expires_at timestamptz not null,
                created_at timestamptz not null,
                constraint service_credentials_secret_hash check (secret_hash ~ '^[0-9a-f]{64}$'),
                constraint service_credentials_generation check (credential_generation >= 1),
                constraint service_credentials_issuer_kind check (issuer_kind in ('agent', 'service')),
                constraint service_credentials_ceiling check (
                    classification_ceiling in ('public', 'internal', 'confidential', 'personal', 'sensitive')
                ),
                constraint service_credentials_agent_ceiling check (
                    issuer_kind <> 'agent' or classification_ceiling in ('public', 'internal', 'confidential')
                ),
                constraint service_credentials_expiry check (expires_at > created_at)
            )
            SQL);
        $connection->statement('create unique index service_credentials_secret_hash_key on service_credentials (secret_hash)');
        $connection->statement('create index service_credentials_actor_id on service_credentials (actor_id)');

        $connection->statement(<<<'SQL'
            create table service_credential_delegations (
                credential_id uuid not null references service_credentials (id),
                position smallint not null,
                actor_id uuid not null references actors (id),
                primary key (credential_id, position),
                constraint service_credential_delegations_position check (position >= 0),
                constraint service_credential_delegations_once unique (credential_id, actor_id)
            )
            SQL);
        $connection->statement('create index service_credential_delegations_actor_id on service_credential_delegations (actor_id)');

        $privileges = new TablePrivileges($connection);

        foreach (self::TABLES as $table) {
            $connection->statement(sprintf('alter table %s enable row level security', $table));
            $connection->statement(sprintf('alter table %s force row level security', $table));
            $connection->statement(sprintf('create policy %1$s_read on %1$s for select using (true)', $table));
            $connection->statement(sprintf('create policy %1$s_owner_write on %1$s for all to current_user using (true) with check (true)', $table));
            $privileges->limitTo($table, [TablePrivilege::Select]);
        }
    }

    public function down(): void
    {
        DB::connection($this->getConnection())->statement('drop table service_credential_delegations, service_credentials, actors');
    }
};
