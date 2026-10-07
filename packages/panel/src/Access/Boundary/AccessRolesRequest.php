<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Access\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Results\QueryResult;
use Cbox\Cms\Core\Access\Domain\Queries\ListRoles;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCall;
use Cbox\Cms\Panel\Access\Domain\AccessRoles;
use Cbox\Cms\Panel\Access\Domain\Dto\AccessRolesSectionsV1;
use Cbox\Cms\Panel\Boundary\PanelReads;
use Cbox\Cms\Panel\Contributions\Boundary\ContributionProps;
use Cbox\Cms\Panel\Contributions\Domain\Dto\PanelView;
use Cbox\Cms\Panel\Contributions\Domain\Dto\RenderedPoint;
use Cbox\Cms\Panel\Domain\Dto\ReadAnswer;
use Illuminate\Http\Request;
use LogicException;

/**
 * A request of the roles page (PRD 5.10, 13.4), read for its controller: the view of the page,
 * which renders its sections point; the call of role.list as the person who signed in, from the
 * session credential (PanelReads), for the page of roles after the id the address names
 * (ListCursor); and the read's answer as the page shows it, the result's document or the problem
 * details of a rejection. The controller holds no logic, so what the page is made of is named here.
 */
#[Internal]
final readonly class AccessRolesRequest
{
    public function __construct(
        private ContributionProps $contributions,
        private PanelReads $reads,
    ) {}

    /**
     * The view of the page for the person: its sections point.
     *
     * @throws LogicException for a request the panel did not authenticate
     */
    public function view(Request $request): PanelView
    {
        return $this->contributions->view($request, AccessRoles::PAGE, [new RenderedPoint(AccessRoles::sections(), new AccessRolesSectionsV1)]);
    }

    /**
     * The call of role.list as the person who signed in, for the page after the id the address names.
     *
     * @throws LogicException for a request the panel did not authenticate
     */
    public function call(Request $request): QueryCall
    {
        return $this->reads->call($request, new ListRoles(ListCursor::of($request, RoleId::fromString(...))));
    }

    /**
     * The answer of the read as the page shows it.
     */
    public function answer(QueryResult $read): ReadAnswer
    {
        return $this->reads->answer($read, $this->reads->codec(AccessRoles::query()));
    }
}
