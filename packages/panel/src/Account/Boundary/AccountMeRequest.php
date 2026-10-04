<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Account\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Results\QueryResult;
use Cbox\Cms\Core\Identity\Domain\Queries\WhoAmI;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCall;
use Cbox\Cms\Panel\Account\Domain\AccountMe;
use Cbox\Cms\Panel\Account\Domain\Dto\AccountMeSectionsV1;
use Cbox\Cms\Panel\Boundary\PanelReads;
use Cbox\Cms\Panel\Contributions\Boundary\ContributionProps;
use Cbox\Cms\Panel\Contributions\Domain\Dto\PanelView;
use Cbox\Cms\Panel\Contributions\Domain\Dto\RenderedPoint;
use Cbox\Cms\Panel\Domain\Dto\ReadAnswer;
use Cbox\Cms\Panel\Shell\Domain\OwnPage;
use Illuminate\Http\Request;
use LogicException;

/**
 * A request of the who-am-I page (PRD 5.16, 13.4), read for its controller: the view of the page,
 * which renders the sections point with the viewer's actor id as its props; the call of actor.me as
 * the person who signed in, from the session credential (PanelReads); and the read's answer as the
 * page shows it, the result's document or the problem details of a rejection. The controller holds
 * no logic, so what the page is made of is named here.
 */
#[Internal]
final readonly class AccountMeRequest
{
    public function __construct(
        private ContributionProps $contributions,
        private PanelReads $reads,
    ) {}

    /**
     * The view of the page for the person: its sections point, with the viewer's actor id.
     *
     * @throws LogicException for a request the panel did not authenticate
     */
    public function view(Request $request): PanelView
    {
        $viewer = $this->contributions->viewer($request, OwnPage::AccountMe->value);

        return $this->contributions->view($request, OwnPage::AccountMe->value, [new RenderedPoint(AccountMe::sections(), new AccountMeSectionsV1($viewer->actor))]);
    }

    /**
     * The call of actor.me as the person who signed in.
     *
     * @throws LogicException for a request the panel did not authenticate
     */
    public function call(Request $request): QueryCall
    {
        return $this->reads->call($request, new WhoAmI);
    }

    /**
     * The answer of the read as the page shows it.
     */
    public function answer(QueryResult $read): ReadAnswer
    {
        return $this->reads->answer($read, $this->reads->codec(AccountMe::query()));
    }
}
