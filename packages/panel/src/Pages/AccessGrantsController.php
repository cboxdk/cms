<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Pages;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Panel\Access\Boundary\AccessGrantsRequest;
use Cbox\Cms\Panel\Boundary\PanelPages;
use Cbox\Cms\Panel\Contributions\Actions\ResolveContributions;
use Cbox\Cms\Panel\Contributions\Actions\RunContributionData;
use Cbox\Cms\Panel\Contributions\Boundary\ContributionProps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The grants page of a person who logged in (PRD 5.10, 13.4), at `<prefix>/access/grants`: the
 * grants that have not ended on the nodes the person reaches, read with grant.list through the
 * QueryPipeline as the person, from the session credential, a page at a time, with the result
 * codec's document in the page's props, the locales the form offers, the pickers of the form as an
 * optional prop read through the pipeline when the page asks for it, and the contributions active
 * for the person on the points it renders. It holds no logic of its own: AccessGrantsRequest reads
 * the request, and PanelPages answers it.
 */
#[Internal]
final readonly class AccessGrantsController
{
    public function __construct(
        private PanelPages $pages,
        private ContributionProps $contributions,
        private AccessGrantsRequest $page,
        private ResolveContributions $resolve,
        private RunContributionData $data,
        private QueryPipeline $pipeline,
    ) {}

    public function __invoke(Request $request): Response|JsonResponse
    {
        $active = $this->resolve->resolve($this->page->view($request));
        $read = $this->pipeline->run($this->page->call($request));

        return $this->pages->accessGrants(
            $request,
            $this->page->answer($read),
            $this->page->locales(),
            $this->contributions->props($request, $active, $this->data->run(...), $this->data->refused(...)),
            $this->page->pickers($request, $this->pipeline->run(...)),
        );
    }
}
