<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Pages;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Panel\Boundary\PanelPages;
use Cbox\Cms\Panel\Contributions\Actions\ResolveContributions;
use Cbox\Cms\Panel\Contributions\Actions\RunContributionData;
use Cbox\Cms\Panel\Contributions\Boundary\ContributionProps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The panel's start page for a person who logged in (PRD 13.4), with the contributions active for
 * them on the points it renders. It holds no logic of its own.
 */
#[Internal]
final readonly class HomeController
{
    public function __construct(
        private PanelPages $pages,
        private ContributionProps $contributions,
        private ResolveContributions $resolve,
        private RunContributionData $data,
    ) {}

    public function __invoke(Request $request): Response|JsonResponse
    {
        $active = $this->resolve->resolve($this->contributions->view($request, PanelPages::HOME_PAGE));

        return $this->pages->home($request, $this->contributions->props($request, $active, $this->data->run(...), $this->data->refused(...)));
    }
}
