<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Doctor\Domain\Probes;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Doctor\Domain\Dto\PostgresRole;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;

/**
 * The credential store of the local accounts as the doctor sees it (PRD 5.16): the identity
 * connection, which cbox-cms.identity.connection names and which logs in as the identity role,
 * and what the app role, on the doctor's own connection, may do in the store's schema. Every
 * method but target() throws ProbeFailed when it cannot answer.
 */
#[Internal]
interface CredentialStoreProbe
{
    /**
     * Where the identity connection goes, without the password.
     */
    public function target(): string;

    /**
     * Connects on the identity connection and returns the role it logs in as.
     *
     * @throws ProbeFailed unavailable when the server cannot be reached, violation when it refuses
     *                     the login or the connection is not configured as Postgres
     */
    public function identityLogin(): string;

    /**
     * The role the app role's connection logs in as.
     *
     * @throws ProbeFailed
     */
    public function appLogin(): string;

    /**
     * The owner of the credential store's schema, or null when the schema does not exist.
     *
     * @throws ProbeFailed
     */
    public function schemaOwner(): ?string;

    /**
     * The attributes of the identity role and the roles with more power it is a member of.
     *
     * @throws ProbeFailed
     */
    public function identityRole(): PostgresRole;

    /**
     * Each privilege the app role holds on the credential store's schema or on a relation in it,
     * directly, through a role it is a member of or through PUBLIC, such as "SELECT on
     * cms_identity.local_accounts", sorted.
     *
     * @return list<string>
     *
     * @throws ProbeFailed
     */
    public function appPrivileges(): array;
}
