<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Inertia;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCall;
use Cbox\Cms\Core\Reads\Domain\QueryCodecs;
use Cbox\Cms\Http\Inertia\Boundary\InertiaQueryOutcome;
use Cbox\Cms\Http\Inertia\Boundary\InertiaQueryRefused;
use Cbox\Cms\Http\Inertia\Boundary\InertiaQueryRequest;
use Cbox\Cms\Http\Inertia\Domain\InertiaActions;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Inertia\Response;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A read through the Inertia profile (GUARDRAILS 2.1): the route names the query and its version,
 * which must be a query action the registry exposes on Inertia, or the answer is 404, and the page
 * component in its default InertiaRoutes::COMPONENT. The visit is read by InertiaQueryRequest with
 * the query's codec, the read runs through the QueryPipeline, and InertiaQueryOutcome renders the
 * page with the result or the problem in its props. The controller holds no logic of its own.
 */
#[Internal]
final readonly class InertiaQueryController
{
    public function __construct(
        private InertiaActions $actions,
        private QueryCodecs $codecs,
        private InertiaQueryRequest $requests,
        private QueryPipeline $pipeline,
        private InertiaQueryOutcome $outcomes,
    ) {}

    public function __invoke(Request $request, string $query, string $version): Response
    {
        $entry = $this->actions->query(new CommandName($query), (int) $version)
            ?? throw new NotFoundHttpException(sprintf('The Inertia profile exposes no version %s of the query %s.', $version, $query));
        $codec = $this->codecs->for($entry->command, $entry->commandVersion);
        $component = $this->component($request);

        try {
            $input = $this->requests->read($request, $codec);
        } catch (InertiaQueryRefused $refused) {
            return $this->outcomes->refused($component, $refused);
        }

        return $this->outcomes->read($component, $this->pipeline->run(new QueryCall($input->query, $input->credential, Surface::Inertia)), $codec);
    }

    private function component(Request $request): string
    {
        $route = $request->route();
        $component = $route instanceof Route ? $route->defaults[InertiaRoutes::COMPONENT] ?? null : null;

        return is_string($component) && $component !== '' ? $component : throw new LogicException(sprintf('The Inertia query route has no page component in its default %s; register it with InertiaRoutes::queries().', InertiaRoutes::COMPONENT));
    }
}
