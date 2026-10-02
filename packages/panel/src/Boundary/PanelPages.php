<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Inertia\ResponseFactory;
use LogicException;

/**
 * Renders the panel's pages that need no action: the Inertia page the panel shows for a path it
 * does not have. The page gets the address of the panel's start, the prefix of the route that
 * matched, so it can link back.
 */
#[Internal]
final readonly class PanelPages
{
    /** The Inertia page for a path the panel does not have, in js/panel/src/pages. */
    public const string NOT_FOUND = 'Errors/NotFound';

    public function __construct(private ResponseFactory $inertia) {}

    public function notFound(Request $request): Response|JsonResponse
    {
        $response = $this->inertia->render(self::NOT_FOUND, ['home' => $this->home($request)])->toResponse($request);

        if (! $response instanceof Response && ! $response instanceof JsonResponse) {
            throw new LogicException('Inertia answered the panel page with a response of another kind.');
        }

        $response->setStatusCode(404);

        return $response;
    }

    /**
     * The panel's start: the root of the prefix the matched route was registered with.
     */
    private function home(Request $request): string
    {
        $route = $request->route();
        $prefix = $route instanceof Route ? $route->getPrefix() : null;

        return '/'.trim((string) $prefix, '/');
    }
}
