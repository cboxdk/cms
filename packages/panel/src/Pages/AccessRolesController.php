<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Pages;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Panel\Access\Boundary\AccessRolesRequest;
use Cbox\Cms\Panel\Boundary\PanelPages;
use Cbox\Cms\Panel\Contributions\Actions\ResolveContributions;
use Cbox\Cms\Panel\Contributions\Actions\RunContributionData;
use Cbox\Cms\Panel\Contributions\Boundary\ContributionProps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The roles page of a person who logged in (PRD 5.10, 13.4), at `<prefix>/access/roles`: the roles
 * of the installation, read with role.list through the QueryPipeline as the person, from the
 * session credential, a page at a time, with the result codec's document in the page's props, and
 * the contributions active for the person on the points it renders. It holds no logic of its own:
 * AccessRolesRequest reads the request, and PanelPages answers it.
 */
#[Internal]
final readonly class AccessRolesController
{
    public function __construct(
        private PanelPages $pages,
        private ContributionProps $contributions,
        private AccessRolesRequest $page,
        private ResolveContributions $resolve,
        private RunContributionData $data,
        private QueryPipeline $pipeline,
    ) {}

    public function __invoke(Request $request): Response|JsonResponse
    {
        $active = $this->resolve->resolve($this->page->view($request));
        $read = $this->pipeline->run($this->page->call($request));

        return $this->pages->accessRoles($request, $this->page->answer($read), $this->contributions->props($request, $active, $this->data->run(...), $this->data->refused(...)));
    }
}
