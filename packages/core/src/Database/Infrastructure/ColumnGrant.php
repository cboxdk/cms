<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Database\Infrastructure;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Database\Domain\TablePrivilege;

/**
 * One privilege on one column of a table for one role, as TablePrivileges reads it from the
 * catalog. The role and the column are quoted for SQL, and PUBLIC is `public`.
 */
#[Experimental]
final readonly class ColumnGrant
{
    public function __construct(
        public string $role,
        public string $column,
        public TablePrivilege $privilege,
        public bool $grantable,
    ) {}
}
