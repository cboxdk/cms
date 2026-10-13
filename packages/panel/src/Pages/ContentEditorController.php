<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Pages;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Panel\Boundary\PanelPages;
use Cbox\Cms\Panel\Content\Boundary\ContentEditorRequest;
use Cbox\Cms\Panel\Content\Domain\Dto\EditorTarget;
use Cbox\Cms\Panel\Contributions\Actions\ResolveContributions;
use Cbox\Cms\Panel\Contributions\Actions\RunContributionData;
use Cbox\Cms\Panel\Contributions\Boundary\ContributionProps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The entry editor of a person who logged in (PRD 5.4, 11.12, 13.4), at
 * `<prefix>/content/<owner>:<handle>/new` for a new entry of the type and
 * `<prefix>/content/<owner>:<handle>/<entry>` for an entry: the type's editor descriptor read with
 * type.describe, the entry read with entry.get and, for a new entry, the nodes its home may be
 * read with node.list, each through the QueryPipeline as the person, from the session credential,
 * with the result codec's document in the page's props, and the contributions active for the
 * person on the points it renders; an address that names a type the installation does not have,
 * or an entry that is no id, is the page for a path the panel does not have. A save is a command
 * the page posts to the Inertia profile, which answers with a redirect back here. It holds no
 * logic of its own: ContentEditorRequest reads the request, and PanelPages answers it.
 */
#[Internal]
final readonly class ContentEditorController
{
    public function __construct(
        private PanelPages $pages,
        private ContributionProps $contributions,
        private ContentEditorRequest $page,
        private ResolveContributions $resolve,
        private RunContributionData $data,
        private QueryPipeline $pipeline,
    ) {}

    public function __invoke(Request $request): Response|JsonResponse
    {
        $target = $this->page->target($request);

        if (! $target instanceof EditorTarget) {
            return $this->pages->notFound($request);
        }

        $active = $this->resolve->resolve($this->page->view($request));

        return $this->pages->contentEditor(
            $request,
            $target,
            $this->page->list($target),
            $this->page->type($request, $target, $this->pipeline->run(...)),
            $this->page->entry($request, $target, $this->pipeline->run(...)),
            $this->page->nodes($request, $target, $this->pipeline->run(...)),
            $this->contributions->props($request, $active, $this->data->run(...), $this->data->refused(...)),
        );
    }
}
