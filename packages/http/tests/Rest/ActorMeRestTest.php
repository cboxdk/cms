<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Tests\Rest;

use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ActorMeCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\KernelCommandCodecs;
use Cbox\Cms\Core\Codecs\Boundary\Generated\KernelQueryCodecs;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Reads\Domain\QueryCodecs;
use Cbox\Cms\Core\Registry\Adapter\FileOpenApiDocuments;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\RestRoute;
use Cbox\Cms\Core\Tests\Access\ListingActionWorld;
use Cbox\Cms\Core\Tests\Access\ListingWorld;
use Cbox\Cms\Core\Tests\Registry\RegistryFixtures;
use Cbox\Cms\Http\Inertia\Boundary\InertiaOutcome;
use Cbox\Cms\Http\Inertia\Boundary\InertiaQueryOutcome;
use Cbox\Cms\Http\Inertia\Domain\InertiaActions;
use Cbox\Cms\Http\Rest\Boundary\RestResponse;
use Cbox\Cms\Http\Rest\RestRoutes;
use Cbox\Cms\Tests\Support\SurfaceContract\SurfaceContractCases;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Routing\Router;
use Illuminate\Testing\TestResponse;
use Override;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * actor.me on REST (GUARDRAILS 2.1, PRD 5.16, 8.8; Sylvester 29 September: the panel and REST stay
 * in parity): the installation's registry, with REST's routes registered as an application
 * registers them, over the QueryPipeline of the ListingActionWorld's fakes. A service actor with a
 * Bearer credential, the one kind of viewer that reaches REST in part 1 of B1, gets its own self
 * as the result codec's document from GET /v1/queries/actor.me/v1, the same document the panel's
 * Inertia query route gives it; the anonymous principal gets problem details with unauthorized;
 * and the OpenAPI document cms:build writes lists the route with the result's schema.
 */
final class ActorMeRestTest extends TestCase
{
    private const string PATH = '/v1/queries/actor.me/v1';

    private const string INERTIA = '/workbench/inertia/queries/actor.me/v1';

    private ?CompiledRegistry $registry = null;

    private ?ListingActionWorld $world = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = SurfaceContractCases::installation(app());
        $this->world = new ListingActionWorld;
        app()->instance(InertiaActions::class, new InertiaActions($this->registry));
        app()->instance(QueryPipeline::class, $this->world->pipeline());
        $router = app(Router::class);
        RestRoutes::register($router, $this->registry);
        $router->getRoutes()->refreshNameLookups();
    }

    #[Override]
    protected function tearDown(): void
    {
        RegistryFixtures::cleanUp();
        $this->registry = null;
        $this->world = null;

        parent::tearDown();
    }

    #[Test]
    public function it_gives_a_service_actor_with_a_bearer_credential_its_own_self_as_the_result_codec_writes_it(): void
    {
        $world = $this->world ?? self::fail('No world.');
        $credential = $world->reader([], ClassificationAccess::Public);
        $response = $this->rest($credential);
        $expected = new ActorMeCodecV1()->encode(ListingWorld::own(ListingWorld::ADMIN), ClassificationAccess::Public);

        $response->assertOk()->assertHeader('Content-Type', 'application/json')->assertHeader('Cache-Control', RestResponse::CACHE_CONTROL);
        self::assertSame($expected, (string) $response->getContent());
        self::assertStringContainsString('"email":"ada@example.com"', $expected);
        self::assertSame(self::PATH, $this->restPath());
    }

    #[Test]
    public function it_gives_the_same_document_in_the_props_of_the_inertia_query_route(): void
    {
        $world = $this->world ?? self::fail('No world.');
        $credential = $world->reader([], ClassificationAccess::Public);
        $rest = (string) $this->rest($credential)->getContent();
        $response = $this->withHeaders(['X-Inertia' => 'true', 'Authorization' => 'Bearer '.$credential->reveal()])
            ->get(self::INERTIA.'?'.http_build_query([RestRoute::QUERY_PARAMETER => '{}']));
        $page = json_decode((string) $response->getContent(), false, 512, JSON_THROW_ON_ERROR);
        $props = $page instanceof stdClass && ($page->props ?? null) instanceof stdClass ? $page->props : new stdClass;

        $response->assertOk();
        self::assertSame($rest, json_encode($props->{InertiaQueryOutcome::RESULT} ?? null, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        self::assertNull($props->{InertiaOutcome::PROBLEM_PROP} ?? null);
    }

    #[Test]
    public function it_refuses_the_anonymous_principal_with_problem_details(): void
    {
        $response = $this->withHeaders(['Accept' => 'application/json'])->get(self::PATH);

        $response->assertStatus(403)->assertHeader('Content-Type', 'application/problem+json');
        self::assertStringContainsString('"code":"unauthorized"', (string) $response->getContent());
        self::assertStringContainsString('actor.me', (string) $response->getContent());
    }

    #[Test]
    public function the_openapi_document_lists_the_route_with_the_result_s_schema(): void
    {
        $directory = RegistryFixtures::scratch();
        self::assertTrue(mkdir($directory, 0o755, true));
        $registry = $this->registry ?? self::fail('No registry.');
        $route = $this->restPath();
        $documents = new FileOpenApiDocuments($directory, new CommandCodecs(...KernelCommandCodecs::all()), new QueryCodecs(...KernelQueryCodecs::all()));
        $documents->write($documents->describe($registry));
        $json = file_get_contents($directory.'/'.FileOpenApiDocuments::FILE);
        self::assertIsString($json);
        $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $paths = is_array($document) && is_array($document['paths'] ?? null) ? $document['paths'] : [];

        self::assertArrayHasKey($route, $paths);
        self::assertArrayHasKey('get', is_array($paths[$route]) ? $paths[$route] : []);
        self::assertStringContainsString('actor.me result, contract version 1', $json);
    }

    /**
     * @return TestResponse<Response>
     */
    private function rest(TransportCredential $credential): TestResponse
    {
        return $this->withHeaders(['Accept' => 'application/json', 'Authorization' => 'Bearer '.$credential->reveal()])
            ->get($this->restPath().'?'.http_build_query([RestRoute::QUERY_PARAMETER => '{}']));
    }

    /**
     * The path of actor.me's REST route in the compiled registry.
     */
    private function restPath(): string
    {
        foreach ($this->registry instanceof CompiledRegistry ? $this->registry->rest : [] as $route) {
            if ($route->name->value === 'actor.me') {
                return $route->path;
            }
        }

        self::fail('The registry has no REST route of actor.me.');
    }
}
