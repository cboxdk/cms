<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The credential store of the local accounts (PRD 5.16, "Lokale konti"), created by the owner role
 * in the schema cms_identity, which the operator creates with the identity role's grants
 * (docker/postgres/sql/database.sql, docs/security/credential-store.md).
 *
 * `local_accounts` holds one local account per actor: the login identifier, lowercased and unique,
 * and the password hash, an Argon2id hash in PHP's encoded form. The actor is the primary key and
 * refers to `actors` in the kernel's schema, the first schema of the owner's search path, so there
 * is one list of people: a registration creates the actor as pending, then writes the account
 * with the actor's id, and then makes the actor active.
 *
 * `password_reset_tokens` holds a reset token of a local account as the SHA-256 of the token, its
 * key, with when it expires and when it was used. The token itself is never stored.
 *
 * The tables have no row level security: the app role has no privilege on the schema or its
 * tables, so isolation is by privilege (identity.credential_isolation). The identity role gets
 * SELECT, INSERT, UPDATE and DELETE on them through the owner's default privileges in the schema.
 * Every column has a CHECK on its form, so a write that would store something else fails.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->statement(<<<'SQL'
            create table cms_identity.local_accounts (
                actor_id uuid primary key references actors (id),
                login text not null,
                password_hash text not null,
                password_changed_at timestamptz not null,
                version bigint not null,
                created_at timestamptz not null,
                constraint local_accounts_login check (
                    login = lower(login) and char_length(login) between 1 and 254 and login !~ '[[:space:][:cntrl:]]'
                ),
                constraint local_accounts_password_hash check (
                    password_hash ~ '^\$argon2id\$v=19\$m=[0-9]+,t=[0-9]+,p=[0-9]+\$[A-Za-z0-9+/]+\$[A-Za-z0-9+/]+$'
                ),
                constraint local_accounts_password_changed_at check (password_changed_at >= created_at),
                constraint local_accounts_version check (version >= 1)
            )
            SQL);
        $connection->statement('create unique index local_accounts_login_key on cms_identity.local_accounts (login)');

        $connection->statement(<<<'SQL'
            create table cms_identity.password_reset_tokens (
                token_hash text primary key,
                actor_id uuid not null references cms_identity.local_accounts (actor_id),
                expires_at timestamptz not null,
                used_at timestamptz,
                created_at timestamptz not null,
                constraint password_reset_tokens_token_hash check (token_hash ~ '^[0-9a-f]{64}$'),
                constraint password_reset_tokens_expiry check (expires_at > created_at),
                constraint password_reset_tokens_used_at check (used_at is null or used_at between created_at and expires_at)
            )
            SQL);
        $connection->statement('create index password_reset_tokens_actor_id on cms_identity.password_reset_tokens (actor_id)');
    }

    public function down(): void
    {
        DB::connection($this->getConnection())->statement('drop table cms_identity.password_reset_tokens, cms_identity.local_accounts');
    }
};
