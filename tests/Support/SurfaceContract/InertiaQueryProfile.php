<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\SurfaceContract;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\RestRoute;
use Cbox\Cms\Http\Inertia\Boundary\InertiaOutcome;
use Cbox\Cms\Http\Inertia\Boundary\InertiaQueryOutcome;
use Cbox\Cms\Http\Inertia\Domain\InertiaActions;
use Cbox\Cms\Tests\TestCase;
use JsonException;
use Override;
use stdClass;

/**
 * Reads in the panel's Inertia pages, with errors in page props (GUARDRAILS 2.1): the workbench's
 * query route, /workbench/inertia/queries/{query}/v{version}, with InertiaActions over the
 * registry, and a page visit as an Inertia client makes it, with the query's document in the query
 * parameter and a service actor's credential as the Bearer token. Every visit renders the page
 * Workbench/Query: an answered read with the result's document in the prop `result`, the JSON the
 * result's codec wrote, and a rejection with the problem in the prop `problem`, the paths of a
 * refused document below `query`.
 */
final readonly class InertiaQueryProfile implements QuerySurfaceProfile
{
    public const string ROUTE = '/workbench/inertia/queries';

    public const string COMPONENT = 'Workbench/Query';

    #[Override]
    public function surface(): Surface
    {
        return Surface::Inertia;
    }

    #[Override]
    public function prepare(TestCase $test, CompiledRegistry $registry): void
    {
        app()->instance(InertiaActions::class, new InertiaActions($registry));
    }

    #[Override]
    public function credential(QueryContractKernel $kernel): TransportCredential
    {
        return $kernel->world->credential;
    }

    /**
     * @throws JsonException when the page is not JSON
     */
    #[Override]
    public function send(TestCase $test, CompiledRegistry $registry, QuerySurfaceCall $call): QueryAnswer
    {
        $response = $test->withHeaders(['X-Inertia' => 'true', 'Authorization' => 'Bearer '.$call->credential->reveal()])
            ->get(sprintf('%s/%s/v%d?%s', self::ROUTE, $call->query->value, $call->version, http_build_query([RestRoute::QUERY_PARAMETER => $call->document])));
        $page = json_decode((string) $response->getContent(), false, 512, JSON_THROW_ON_ERROR);
        $props = $page instanceof stdClass && $page->props instanceof stdClass ? $page->props : new stdClass;
        $component = $page instanceof stdClass && is_string($page->component ?? null) ? $page->component : '-';
        $problem = $props->{InertiaOutcome::PROBLEM_PROP} ?? null;
        $result = $props->{InertiaQueryOutcome::RESULT} ?? null;
        $transport = sprintf('HTTP %d page %s with %s', $response->getStatusCode(), $component, $problem instanceof stdClass ? 'problem' : ($result instanceof stdClass ? 'result' : 'neither'));
        $rejected = $problem instanceof stdClass;

        return QueryAnswer::of(json_encode($rejected ? $problem : $result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $rejected, $transport);
    }

    #[Override]
    public function queryPath(string $path): string
    {
        return $path === '' ? RestRoute::QUERY_PARAMETER : RestRoute::QUERY_PARAMETER.'.'.$path;
    }

    #[Override]
    public function transport(QueryScenario $scenario): string
    {
        return sprintf('HTTP 200 page %s with %s', self::COMPONENT, $scenario === QueryScenario::Answered ? 'result' : 'problem');
    }
}
