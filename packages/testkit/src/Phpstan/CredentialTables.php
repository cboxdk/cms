<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The tables of the credential store of the local accounts (PRD 5.16, "Lokale konti"): every table
 * of the schema SCHEMA, which the identity module's migrations create there. They are not kernel
 * tables: the app role has no privilege on them, and only the identity module writes them, on the
 * identity role's connection. KernelTableWriteRule reports a write by name anywhere else. A test in
 * cboxdk/cms holds TABLES equal to the identity module's.
 */
#[Internal]
final class CredentialTables
{
    /** The schema of the credential store. */
    public const string SCHEMA = 'cms_identity';

    /**
     * The tables the identity module's migrations create in SCHEMA.
     *
     * @var list<string>
     */
    public const array TABLES = ['local_accounts', 'password_reset_tokens'];

    /**
     * The credential store table a name refers to, as `cms_identity.<table>`, read without case,
     * quotes or whitespace, or null: any table qualified with SCHEMA, and a table of TABLES without
     * a schema, which the identity role's search path, SCHEMA, resolves to the store.
     */
    public static function of(string $name): ?string
    {
        $parts = explode('.', strtolower((string) preg_replace('/\s+/', '', str_replace('"', '', $name))));

        if (count($parts) === 2 && $parts[0] === self::SCHEMA && $parts[1] !== '') {
            return self::SCHEMA.'.'.$parts[1];
        }

        if (count($parts) === 1 && in_array($parts[0], self::TABLES, true)) {
            return self::SCHEMA.'.'.$parts[0];
        }

        return null;
    }
}
