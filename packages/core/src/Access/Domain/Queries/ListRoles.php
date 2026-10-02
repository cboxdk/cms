<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain\Queries;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Query as QueryName;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Core\Reads\Domain\ListLimit;
use InvalidArgumentException;

/**
 * Lists the roles with their classification ceilings and permissions (PRD 5.10), version 1 of
 * role.list: a page of at most $limit roles in the order of their ids, after the role $after, or
 * from the first role when it is null. It needs a role whose permissions name role.list.
 */
#[QueryName('role.list', version: 1)]
#[Experimental]
final readonly class ListRoles implements Query
{
    /**
     * @throws InvalidArgumentException when the limit is outside ListLimit
     */
    public function __construct(
        public ?RoleId $after = null,
        public int $limit = ListLimit::DEFAULT,
    ) {
        ListLimit::checked($limit);
    }
}
