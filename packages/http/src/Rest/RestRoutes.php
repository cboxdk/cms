<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Rest;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\RestRoute;
use Cbox\Cms\Http\Rest\Boundary\RestRequest;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Routing\Route;

/**
 * The routes of the REST surface (GUARDRAILS 2.1, PRD 8.8), registered from the table cms:build
 * compiled into rest.php: one route per action exposed on REST, below /v1, each with its path and
 * method from the table, such as POST /v1/commands/entry.create/v1 and GET
 * /v1/queries/entry.list/v1. A write is served by RestCommandController and a read by
 * RestQueryController; each route carries the name and version of its command or query as the
 * route defaults RestRequest::NAME and RestRequest::VERSION, and is named `cbox-cms.rest.<commands|queries>.<name>.v<version>`.
 *
 * An application registers them in its API routes, with the registry the container reads from the
 * cache: RestRoutes::register($router, app(CompiledRegistry::class)). They need no session, cookies
 * or CSRF token: the caller authenticates with a Bearer credential.
 */
#[Experimental]
final readonly class RestRoutes
{
    /** The prefix of the name of every route. */
    public const string ROUTE_NAME = 'cbox-cms.rest.';

    /**
     * @return list<Route> the routes, in the order of the table
     */
    public static function register(Registrar $router, CompiledRegistry $registry): array
    {
        return array_map(
            static fn (RestRoute $route): Route => ($route->kind === ActionKind::Write
                ? $router->post($route->path, RestCommandController::class)
                : $router->get($route->path, RestQueryController::class))
                ->defaults(RestRequest::NAME, $route->name->value)
                ->defaults(RestRequest::VERSION, (string) $route->version)
                ->name(self::name($route)),
            $registry->rest,
        );
    }

    /**
     * The name of the route, such as cbox-cms.rest.commands.entry.create.v1.
     */
    public static function name(RestRoute $route): string
    {
        return sprintf('%s%s.%s.v%d', self::ROUTE_NAME, $route->kind === ActionKind::Write ? 'commands' : 'queries', $route->name->value, $route->version);
    }
}
