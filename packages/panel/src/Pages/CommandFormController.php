<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Pages;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Panel\Boundary\PanelPages;
use Cbox\Cms\Panel\CommandForm\Boundary\CommandFormRequest;
use Cbox\Cms\Panel\Contributions\Actions\ResolveContributions;
use Cbox\Cms\Panel\Contributions\Actions\RunContributionData;
use Cbox\Cms\Panel\Contributions\Boundary\ContributionProps;
use Cbox\Cms\Panel\Domain\Dto\CommandFormPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The generic command form of a person who logged in (PRD 6.1, 13.4), at
 * `<prefix>/commands/{command}/v{version}`, the address the Inertia profile runs the command at:
 * the form is rendered in the browser from the command's JSON Schema, which the page's props
 * carry, and posts to the same address. A command the profile does not expose has no form, so the
 * page for a path the panel does not have is answered instead. It holds no logic of its own:
 * CommandFormRequest reads the request, and PanelPages answers it.
 */
#[Internal]
final readonly class CommandFormController
{
    public function __construct(
        private PanelPages $pages,
        private ContributionProps $contributions,
        private CommandFormRequest $page,
        private ResolveContributions $resolve,
        private RunContributionData $data,
    ) {}

    public function __invoke(Request $request, string $command, string $version): Response|JsonResponse
    {
        $form = $this->page->form($command, $version);

        if (! $form instanceof CommandFormPage) {
            return $this->pages->notFound($request);
        }

        $active = $this->resolve->resolve($this->page->view($request, $form));

        return $this->pages->commandForm($request, $form, $this->contributions->props($request, $active, $this->data->run(...), $this->data->refused(...)));
    }
}
