<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\SurfaceContract;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Core\Registry\Boundary\OpenApiJson;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\RestRoute;
use Cbox\Cms\Http\Rest\RestRoutes;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Routing\Router;
use LogicException;
use Override;

/**
 * Reads on REST with JSON and problem details (GUARDRAILS 2.1, PRD 8.8): the routes of the
 * registry's REST table, registered as an application registers them, and a GET of the query's
 * route with the query's document in the query parameter and a service actor's credential as the
 * Bearer token. An answered read is the result's JSON with 200, a rejection problem details with
 * the catalog's HTTP status, the paths of a refused document below `query`.
 */
final readonly class RestQueryProfile implements QuerySurfaceProfile
{
    private const string RESULT = 'application/json';

    #[Override]
    public function surface(): Surface
    {
        return Surface::Rest;
    }

    #[Override]
    public function prepare(TestCase $test, CompiledRegistry $registry): void
    {
        $router = app(Router::class);
        RestRoutes::register($router, $registry);
        $router->getRoutes()->refreshNameLookups();
    }

    #[Override]
    public function credential(QueryContractKernel $kernel): TransportCredential
    {
        return $kernel->world->credential;
    }

    #[Override]
    public function send(TestCase $test, CompiledRegistry $registry, QuerySurfaceCall $call): QueryAnswer
    {
        $response = $test->call('GET', $this->route($registry, $call)->path, [RestRoute::QUERY_PARAMETER => $call->document], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$call->credential->reveal(),
        ]);
        $type = explode(';', (string) $response->headers->get('Content-Type'))[0];

        return QueryAnswer::of((string) $response->getContent(), $type === OpenApiJson::PROBLEM_MEDIA_TYPE, sprintf('HTTP %d %s', $response->getStatusCode(), $type));
    }

    #[Override]
    public function queryPath(string $path): string
    {
        return $path === '' ? RestRoute::QUERY_PARAMETER : RestRoute::QUERY_PARAMETER.'.'.$path;
    }

    #[Override]
    public function transport(QueryScenario $scenario): string
    {
        return match ($scenario) {
            QueryScenario::DocumentRefused => $this->problem(ErrorCode::JsonInvalid),
            QueryScenario::Unauthorized => $this->problem(ErrorCode::Unauthorized),
            QueryScenario::Answered => 'HTTP 200 '.self::RESULT,
        };
    }

    private function problem(ErrorCode $code): string
    {
        return sprintf('HTTP %d %s', $code->entry()->http->value, OpenApiJson::PROBLEM_MEDIA_TYPE);
    }

    private function route(CompiledRegistry $registry, QuerySurfaceCall $call): RestRoute
    {
        foreach ($registry->rest as $route) {
            if ($route->kind === ActionKind::Query && $route->name->equals($call->query) && $route->version === $call->version) {
                return $route;
            }
        }

        throw new LogicException(sprintf('The REST table of the registry has no route for the query %s version %d.', $call->query->value, $call->version));
    }
}
