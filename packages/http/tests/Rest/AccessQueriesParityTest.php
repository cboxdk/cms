<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Tests\Rest;

use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\RestRoute;
use Cbox\Cms\Core\Tests\Access\ListingActionWorld;
use Cbox\Cms\Http\Inertia\Boundary\InertiaOutcome;
use Cbox\Cms\Http\Inertia\Boundary\InertiaQueryOutcome;
use Cbox\Cms\Http\Inertia\Domain\InertiaActions;
use Cbox\Cms\Http\Rest\Boundary\RestResponse;
use Cbox\Cms\Http\Rest\RestRoutes;
use Cbox\Cms\Tests\Support\SurfaceContract\SurfaceContractCases;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Routing\Router;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use stdClass;

/**
 * The access queries on REST and in the panel's Inertia pages give the same documents for the same
 * actor (GUARDRAILS 2.1, Sylvester 29 September: the panel and REST stay in parity, PRD 12.2): the
 * installation's registry, with REST's routes registered as an application registers them and the
 * workbench's Inertia query route, over the QueryPipeline of the ListingActionWorld's fakes. Each
 * query is read through both by one actor, with its personal fields as the actor's classification
 * access allows them, and the REST body and the Inertia prop `result` must be the same JSON; a
 * refused read gives the same problem on both.
 */
final class AccessQueriesParityTest extends TestCase
{
    private const string INERTIA = '/workbench/inertia/queries';

    private ?CompiledRegistry $registry = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = SurfaceContractCases::installation(app());
        app()->instance(InertiaActions::class, new InertiaActions($this->registry));
        $router = app(Router::class);
        RestRoutes::register($router, $this->registry);
        $router->getRoutes()->refreshNameLookups();
    }

    /**
     * @return array<string, array{string, string, ClassificationAccess}>
     */
    public static function reads(): array
    {
        $reads = [];

        foreach (['role.list' => '{}', 'grant.list' => '{"limit":3}', 'actor.list' => '{}', 'node.list' => '{"after":null,"limit":2}'] as $query => $document) {
            foreach ([ClassificationAccess::Personal, ClassificationAccess::Internal] as $access) {
                $reads[sprintf('%s at %s access', $query, $access->value)] = [$query, $document, $access];
            }
        }

        return $reads;
    }

    #[Test]
    #[DataProvider('reads')]
    public function it_gives_the_same_document_on_rest_and_in_the_inertia_props_for_the_same_actor(string $query, string $document, ClassificationAccess $access): void
    {
        $world = new ListingActionWorld;
        app()->instance(QueryPipeline::class, $world->pipeline());
        $credential = $world->reader(['actor.list', 'grant.list', 'role.list'], $access);

        [$restStatus, $body] = $this->rest($query, $document, $credential);
        [$pageStatus, $props] = $this->inertia($query, $document, $credential);

        self::assertSame(200, $restStatus);
        self::assertSame(200, $pageStatus);
        self::assertSame($body, $this->written($props->{InertiaQueryOutcome::RESULT} ?? null));
        self::assertNull($props->{InertiaOutcome::PROBLEM_PROP} ?? null);

        if ($access === ClassificationAccess::Internal) {
            self::assertStringNotContainsString('@example.com', $body);
        }

        if ($access === ClassificationAccess::Personal && in_array($query, ['grant.list', 'actor.list'], true)) {
            self::assertStringContainsString('"email":"eve@example.com"', $body);
        }
    }

    #[Test]
    public function it_gives_the_same_problem_on_rest_and_in_the_inertia_props_for_a_refused_actor(): void
    {
        $world = new ListingActionWorld;
        app()->instance(QueryPipeline::class, $world->pipeline());
        $credential = $world->reader(['role.list'], ClassificationAccess::Personal);

        [$status, $body] = $this->rest('grant.list', '{}', $credential);
        [, $props] = $this->inertia('grant.list', '{}', $credential);

        self::assertSame(403, $status);
        self::assertSame($body, $this->written($props->{InertiaOutcome::PROBLEM_PROP} ?? null));
        self::assertNull($props->{InertiaQueryOutcome::RESULT} ?? null);
        self::assertStringContainsString('"code":"unauthorized"', $body);
    }

    #[Test]
    public function every_inertia_read_answer_with_personal_fields_or_a_problem_is_never_stored_as_rest_answers_are(): void
    {
        $world = new ListingActionWorld;
        app()->instance(QueryPipeline::class, $world->pipeline());
        $reader = $world->reader(['actor.list', 'grant.list', 'role.list'], ClassificationAccess::Personal);
        $refused = $world->reader(['role.list'], ClassificationAccess::Personal);
        $url = sprintf('%s/actor.list/v1?%s', self::INERTIA, http_build_query([RestRoute::QUERY_PARAMETER => '{}']));
        $invalid = sprintf('%s/actor.list/v1?%s', self::INERTIA, http_build_query([RestRoute::QUERY_PARAMETER => '{"limit":0}']));

        foreach ([[], ['X-Inertia' => 'true']] as $headers) {
            $answered = $this->withHeaders([...$headers, 'Authorization' => 'Bearer '.$reader->reveal()])->get($url);
            $rejected = $this->withHeaders([...$headers, 'Authorization' => 'Bearer '.$refused->reveal()])->get($url);
            $unread = $this->withHeaders([...$headers, 'Authorization' => 'Bearer '.$reader->reveal()])->get($invalid);

            $answered->assertOk();
            $rejected->assertOk();
            self::assertStringContainsString('eve@example.com', (string) $answered->getContent());

            foreach ([$answered, $rejected, $unread] as $response) {
                self::assertSame(RestResponse::CACHE_CONTROL, $response->headers->get('Cache-Control'));
            }
        }
    }

    /**
     * The status and body of a REST read.
     *
     * @return array{int, string}
     */
    private function rest(string $query, string $document, TransportCredential $credential): array
    {
        $path = '';

        foreach ($this->registry instanceof CompiledRegistry ? $this->registry->rest : [] as $route) {
            if ($route->name->value === $query) {
                $path = $route->path;
            }
        }

        $response = $this->withHeaders(['Accept' => 'application/json', 'Authorization' => 'Bearer '.$credential->reveal()])
            ->get($path.'?'.http_build_query([RestRoute::QUERY_PARAMETER => $document]));

        return [$response->getStatusCode(), (string) $response->getContent()];
    }

    /**
     * The status and props of an Inertia page visit that reads.
     *
     * @return array{int, stdClass}
     */
    private function inertia(string $query, string $document, TransportCredential $credential): array
    {
        $response = $this->withHeaders(['X-Inertia' => 'true', 'Authorization' => 'Bearer '.$credential->reveal()])
            ->get(sprintf('%s/%s/v1?%s', self::INERTIA, $query, http_build_query([RestRoute::QUERY_PARAMETER => $document])));
        $page = json_decode((string) $response->getContent(), false, 512, JSON_THROW_ON_ERROR);
        $props = $page instanceof stdClass && ($page->props ?? null) instanceof stdClass ? $page->props : new stdClass;

        return [$response->getStatusCode(), $props];
    }

    /**
     * The JSON of a prop as Inertia carried it, written as the codecs write: no escaped slashes or
     * Unicode.
     */
    private function written(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
