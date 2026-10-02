<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\Result;

/**
 * The result of role.list: a page of roles in the order of their ids, and the id to read the next
 * page after, or null when this page is the last.
 */
#[Experimental]
final readonly class RoleList implements Result
{
    /**
     * @param  list<ListedRole>  $roles
     */
    public function __construct(
        public array $roles,
        public ?RoleId $next,
    ) {}
}
