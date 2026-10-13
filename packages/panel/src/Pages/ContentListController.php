<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Pages;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Panel\Boundary\PanelPages;
use Cbox\Cms\Panel\Content\Boundary\ContentListRequest;
use Cbox\Cms\Panel\Content\Domain\Dto\ContentTarget;
use Cbox\Cms\Panel\Contributions\Actions\ResolveContributions;
use Cbox\Cms\Panel\Contributions\Actions\RunContributionData;
use Cbox\Cms\Panel\Contributions\Boundary\ContributionProps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The content list of a person who logged in (PRD 5.4, 13.4), at `<prefix>/content` for every type
 * and `<prefix>/content/<owner>:<handle>` for one: the entries the person reaches, read with
 * entry.list through the QueryPipeline as the person, from the session credential, a page at a
 * time, with the result codec's document in the page's props, and the contributions active for
 * the person on the points it renders; an address that names a type the installation does not
 * have is the page for a path the panel does not have. It holds no logic of its own:
 * ContentListRequest reads the request, and PanelPages answers it.
 */
#[Internal]
final readonly class ContentListController
{
    public function __construct(
        private PanelPages $pages,
        private ContributionProps $contributions,
        private ContentListRequest $page,
        private ResolveContributions $resolve,
        private RunContributionData $data,
        private QueryPipeline $pipeline,
    ) {}

    public function __invoke(Request $request): Response|JsonResponse
    {
        $target = $this->page->target($request);

        if (! $target instanceof ContentTarget) {
            return $this->pages->notFound($request);
        }

        $active = $this->resolve->resolve($this->page->view($request));
        $read = $this->pipeline->run($this->page->call($request, $target));

        return $this->pages->contentList($request, $target, $this->page->answer($read), $this->contributions->props($request, $active, $this->data->run(...), $this->data->refused(...)));
    }
}
