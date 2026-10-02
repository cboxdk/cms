<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Cbox\Cms\Core\Access\Domain\AccessListings;
use Cbox\Cms\Core\Access\Domain\Dto\RoleList;
use Cbox\Cms\Core\Access\Domain\Queries\ListRoles;
use Override;

/**
 * role.list (PRD 5.10): a page of the roles with their ceilings and permissions, read through
 * AccessListings. It reads one row more than the page holds, to know whether a next page follows.
 * It costs the rows it may return.
 *
 * @implements QueryAction<ListRoles, RoleList>
 */
#[Action(handles: ListRoles::class, surfaces: [Surface::Rest, Surface::Inertia])]
#[Internal]
final readonly class ListRolesAction implements QueryAction
{
    public function __construct(private AccessListings $listings) {}

    /**
     * @param  ListRoles  $query
     */
    #[Override]
    public function cost(Query $query): QueryCost
    {
        return new QueryCost($query->limit);
    }

    /**
     * @param  ListRoles  $query
     */
    #[Override]
    public function handle(Query $query): RoleList
    {
        $roles = $this->listings->roles($query->after, $query->limit + 1);
        $page = array_slice($roles, 0, $query->limit);
        $last = $page === [] ? null : array_last($page);

        return new RoleList($page, count($roles) > $query->limit ? $last?->id : null);
    }
}
