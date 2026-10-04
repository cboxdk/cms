<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Pages;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Panel\Boundary\PanelPages;
use Cbox\Cms\Panel\Contributions\Actions\LocateAddonPage;
use Cbox\Cms\Panel\Contributions\Actions\ResolveContributions;
use Cbox\Cms\Panel\Contributions\Actions\RunContributionData;
use Cbox\Cms\Panel\Contributions\Boundary\AddonPagePath;
use Cbox\Cms\Panel\Contributions\Boundary\ContributionProps;
use Cbox\Cms\Panel\Contributions\Domain\Dto\ActiveFill;
use Cbox\Cms\Panel\Contributions\Domain\Dto\AddonPageRef;
use Cbox\Cms\Panel\Contributions\Domain\Dto\LocatedPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A page of an addon for a person who logged in (PRD 13.4, section 3.4 of the panel extension
 * architecture), at `<prefix>/x/{namespace}/{path}`: the PageContribution of the addon at the
 * path, when the viewer gets it, with the contributions active for them, the page's own data
 * among them; otherwise the page for a path the panel does not have. It holds no logic of its
 * own.
 */
#[Internal]
final readonly class AddonPageController
{
    public function __construct(
        private PanelPages $pages,
        private ContributionProps $contributions,
        private LocateAddonPage $locate,
        private ResolveContributions $resolve,
        private RunContributionData $data,
    ) {}

    public function __invoke(Request $request, string $namespace, string $path): Response|JsonResponse
    {
        $ref = AddonPagePath::read($namespace, $path);
        $located = $ref instanceof AddonPageRef ? $this->locate->locate($ref) : null;

        if (! $located instanceof LocatedPage) {
            return $this->pages->notFound($request);
        }

        $active = $this->resolve->resolve($this->contributions->view($request, $located->page->value));
        $page = $active->page($located->page);

        if (! $page instanceof ActiveFill) {
            return $this->pages->notFound($request);
        }

        return $this->pages->addonPage($request, $page, $this->contributions->props($request, $active, $this->data->run(...), $this->data->refused(...)));
    }
}
