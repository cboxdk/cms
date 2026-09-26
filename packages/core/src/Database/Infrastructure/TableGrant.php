<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Database\Infrastructure;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * One privilege on a table for one role, as TablePrivileges reads it from the catalog. The role
 * is quoted for SQL, and PUBLIC is `public`.
 */
#[Experimental]
final readonly class TableGrant
{
    public function __construct(
        public string $role,
        public string $privilege,
        public bool $grantable,
    ) {}
}
