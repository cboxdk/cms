<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\CredentialStore\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The credential store of the local accounts (PRD 5.16, "Lokale konti"): its own Postgres schema,
 * reached by its own role on its own connection. The app role has no privilege on the schema or
 * its tables, so isolation is by privilege, and the tables have no row level security. Credentials
 * are bound to the actor register, `actors`, so there is one list of people; the store is not
 * content state and lies outside invariant 1.
 *
 * Only this module and its migrations write the tables (the testkit's KernelTableWriteRule).
 */
#[Internal]
final readonly class CredentialStore
{
    /** The schema, created by the operator with the identity role's grants. */
    public const string SCHEMA = 'cms_identity';

    /**
     * The tables of the schema, as the module's migrations create them.
     *
     * @var list<string>
     */
    public const array TABLES = ['local_accounts', 'password_reset_tokens'];

    /**
     * A table of the store, qualified by its schema, so a query reaches it whatever the
     * connection's search path is.
     */
    public static function table(string $table): string
    {
        return self::SCHEMA.'.'.$table;
    }
}
