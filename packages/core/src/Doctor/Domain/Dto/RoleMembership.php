<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * A role the doctor's role is a member of, directly or through other roles, that gives more power
 * than the app role may have (PRD 4.2): a superuser, a role with BYPASSRLS or CREATEROLE, the owner
 * of relations, who can alter or drop them and turn their row level security off, a role that may
 * create objects in the database or its schemas, or one of the predefined roles in
 * PREDEFINED_ROLES.
 */
#[Internal]
final readonly class RoleMembership
{
    /**
     * The predefined roles that give the app role more than its grants: what each one gives.
     *
     * @var array<string, string>
     */
    public const array PREDEFINED_ROLES = [
        'pg_execute_server_program' => 'runs programs on the database server',
        'pg_maintain' => 'maintains, reindexes and locks every table like its owner',
        'pg_read_all_data' => 'reads every table',
        'pg_read_server_files' => 'reads files on the database server',
        'pg_write_all_data' => 'writes every table, append-only ones included',
        'pg_write_server_files' => 'writes files on the database server',
    ];

    /**
     * The predefined role whose members are the owner of the current database and the roles that
     * are members of that owner; it owns the schema public from Postgres 15.
     */
    public const string DATABASE_OWNER = 'pg_database_owner';

    /**
     * @param  bool  $createsObjects  the role owns the database or a schema outside the system schemas, or has CREATE on one of them granted to it and not to PUBLIC
     */
    public function __construct(
        public string $name,
        public bool $superuser,
        public bool $bypassRowSecurity,
        public bool $ownsRelations,
        public bool $createRole,
        public bool $createsObjects,
    ) {}

    /**
     * What the role gives when it is one of PREDEFINED_ROLES.
     */
    public function predefinedPower(): ?string
    {
        return self::PREDEFINED_ROLES[$this->name] ?? null;
    }
}
