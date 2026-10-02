<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The links of actors to IdP identities (PRD 5.16, "Koblinger"), in the identity store's schema
 * cms_identity, created by the owner role. An IdP identity is its connection, issuer and subject,
 * the primary key, and points at one actor in `actors` of the kernel's schema; an actor can have
 * several. The login policy reads the links to refuse a local login of an actor linked to an
 * authoritative connection (invariant 38). The federated connections and SCIM write them.
 *
 * Like the rest of the schema, the table has no row level security: the app role has no privilege
 * on it, and the identity role gets its DML through the owner's default privileges in the schema.
 * Every column has a CHECK on the form of its value object in the contracts (ConnectionId, Issuer,
 * Subject).
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->statement(<<<'SQL'
            create table cms_identity.idp_links (
                connection text not null,
                issuer text not null,
                subject text not null,
                actor_id uuid not null references actors (id),
                created_at timestamptz not null,
                primary key (connection, issuer, subject),
                constraint idp_links_connection check (connection ~ '^[a-z][a-z0-9_-]{0,63}$'),
                constraint idp_links_issuer check (
                    char_length(issuer) <= 255 and issuer ~ '^https?://[^/?#[:space:]@]+(/[^?#[:space:]]*)?$'
                ),
                constraint idp_links_subject check (subject ~ '^[\x21-\x7E]{1,255}$')
            )
            SQL);
        $connection->statement('create index idp_links_actor_id on cms_identity.idp_links (actor_id)');
    }

    public function down(): void
    {
        DB::connection($this->getConnection())->statement('drop table cms_identity.idp_links');
    }
};
