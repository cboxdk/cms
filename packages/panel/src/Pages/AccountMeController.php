<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Pages;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Identity\Domain\Queries\WhoAmI;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Panel\Account\Domain\AccountMe;
use Cbox\Cms\Panel\Account\Domain\Dto\AccountMeSectionsV1;
use Cbox\Cms\Panel\Boundary\PanelPages;
use Cbox\Cms\Panel\Boundary\PanelReads;
use Cbox\Cms\Panel\Contributions\Actions\ResolveContributions;
use Cbox\Cms\Panel\Contributions\Actions\RunContributionData;
use Cbox\Cms\Panel\Contributions\Boundary\ContributionProps;
use Cbox\Cms\Panel\Contributions\Domain\Dto\RenderedPoint;
use Cbox\Cms\Panel\Shell\Domain\OwnPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The who-am-I page of a person who logged in (PRD 5.16, 13.4), at `<prefix>/account/me`: the
 * person's own actor, profile and grants, read with actor.me through the QueryPipeline as the
 * person, from the session credential, with the result codec's document in the page's props, and
 * the contributions active for the person on the points it renders, the sections of the page with
 * the viewer's actor id as their props. It holds no logic of its own.
 */
#[Internal]
final readonly class AccountMeController
{
    public function __construct(
        private PanelPages $pages,
        private ContributionProps $contributions,
        private ResolveContributions $resolve,
        private RunContributionData $data,
        private PanelReads $reads,
        private QueryPipeline $pipeline,
    ) {}

    public function __invoke(Request $request): Response|JsonResponse
    {
        $viewer = $this->contributions->viewer($request, OwnPage::AccountMe->value);
        $active = $this->resolve->resolve($this->contributions->view($request, OwnPage::AccountMe->value, [new RenderedPoint(AccountMe::sections(), new AccountMeSectionsV1($viewer->actor))]));
        $read = $this->pipeline->run($this->reads->call($request, new WhoAmI));

        return $this->pages->accountMe($request, $this->reads->answer($read, $this->reads->codec(AccountMe::query())), $this->contributions->props($request, $active, $this->data->run(...), $this->data->refused(...)));
    }
}
