<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Tests\Rest;

use Cbox\Cms\Contracts\Editors\TypeEditors;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Core\Codecs\Boundary\Generated\TypeDescriptionCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\TypeListCodecV1;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\RestRoute;
use Cbox\Cms\Core\Schema\Domain\Dto\TypeDescription;
use Cbox\Cms\Core\Schema\Domain\Dto\TypeList;
use Cbox\Cms\Core\Tests\Registry\RegistryFixtures;
use Cbox\Cms\Core\Tests\Schema\TypeEditorsWorld;
use Cbox\Cms\Http\Inertia\Boundary\InertiaOutcome;
use Cbox\Cms\Http\Inertia\Boundary\InertiaQueryOutcome;
use Cbox\Cms\Http\Inertia\Domain\InertiaActions;
use Cbox\Cms\Http\Rest\Boundary\RestResponse;
use Cbox\Cms\Http\Rest\RestRoutes;
use Cbox\Cms\Testkit\Editors\SampleEditors;
use Cbox\Cms\Tests\Support\SurfaceContract\SurfaceContractCases;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * type.list and type.describe on REST (GUARDRAILS 2.1, PRD 11.12, 14.1; Sylvester 29 September:
 * the panel and REST stay in parity): the installation's registry, with REST's routes registered
 * as an application registers them, over the QueryPipeline of the TypeEditorsWorld's fakes, and
 * over the installation's own TypeEditors. An actor with a Bearer credential gets the workbench's
 * types from GET /v1/queries/type.list/v1, the same document the panel's Inertia query route gives
 * it; a field above the reader's classification access is absent, and so is a field closed to
 * agents for an agent's credential; both queries read no table, with 3 and 30 types in the fake;
 * and the anonymous principal gets problem details with unauthorized.
 */
final class TypeListRestTest extends TestCase
{
    private const string LIST = '/v1/queries/type.list/v1';

    private const string DESCRIBE = '/v1/queries/type.describe/v1';

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

    #[Override]
    protected function tearDown(): void
    {
        RegistryFixtures::cleanUp();
        $this->registry = null;

        parent::tearDown();
    }

    #[Test]
    public function it_gives_an_actor_the_workbench_s_types_as_the_result_codec_writes_them_and_the_same_document_on_the_inertia_route(): void
    {
        $editors = app(TypeEditors::class);
        $world = new TypeEditorsWorld(editors: $editors);
        app()->instance(QueryPipeline::class, $world->pipeline());
        $credential = $world->reader(ClassificationAccess::Sensitive);
        $response = $this->rest(self::LIST, $credential);
        $expected = new TypeListCodecV1()->encode(new TypeList($editors->all()), ClassificationAccess::Sensitive);

        $response->assertOk()->assertHeader('Content-Type', 'application/json')->assertHeader('Cache-Control', RestResponse::CACHE_CONTROL);
        self::assertSame($expected, (string) $response->getContent());
        self::assertStringContainsString('"name":"app:fixture_article"', $expected);
        self::assertStringContainsString('"name":"app:fixture_measurement"', $expected);
        self::assertStringContainsString('"path":"ext.fixtureaddon.fixture_slug"', $expected);
        self::assertSame($expected, $this->inertia('type.list', '{}', $credential));

        $described = $this->rest(self::DESCRIBE, $credential, '{"name":"app:fixture_article"}');
        $described->assertOk();
        self::assertSame(new TypeDescriptionCodecV1()->encode(new TypeDescription($editors->all()[0]), ClassificationAccess::Sensitive), (string) $described->getContent());
        self::assertSame((string) $described->getContent(), $this->inertia('type.describe', '{"name":"app:fixture_article"}', $credential));
    }

    #[Test]
    public function a_field_above_the_reader_s_classification_access_is_absent_and_so_is_a_field_closed_to_agents_for_an_agent(): void
    {
        $world = new TypeEditorsWorld;
        app()->instance(QueryPipeline::class, $world->pipeline());

        $public = $this->document($this->rest(self::LIST, $world->reader(ClassificationAccess::Public)));
        $agent = $this->document($this->rest(self::DESCRIBE, $world->reader(ClassificationAccess::Sensitive, IssuerKind::Agent), '{"name":"sample:card_1"}'));

        self::assertSame([SampleEditors::PUBLIC_PATHS, SampleEditors::PUBLIC_PATHS, SampleEditors::PUBLIC_PATHS], array_map(fn (mixed $type): array => $this->paths(is_array($type) ? $type : []), is_array($public['types'] ?? null) ? array_values($public['types']) : []));
        self::assertSame(SampleEditors::AGENT_PATHS, $this->paths(is_array($agent['type'] ?? null) ? $agent['type'] : []));
        self::assertStringNotContainsString('diagnosis', (string) json_encode($public));
        self::assertStringNotContainsString('diagnosis', (string) json_encode($agent));
    }

    #[Test]
    #[DataProvider('counts')]
    public function both_queries_read_no_table(int $count): void
    {
        $world = new TypeEditorsWorld($count);
        app()->instance(QueryPipeline::class, $world->pipeline());
        $credential = $world->reader(ClassificationAccess::Internal);
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();

        $list = $this->document($this->rest(self::LIST, $credential));
        $described = $this->document($this->rest(self::DESCRIBE, $credential, sprintf('{"name":"sample:card_%d"}', $count)));

        self::assertCount($count, is_array($list['types'] ?? null) ? $list['types'] : [], 'types with '.$count);
        self::assertSame('sample:card_'.$count, is_array($described['type'] ?? null) ? $described['type']['name'] ?? null : null, 'described with '.$count);
        self::assertSame([], DB::connection()->getQueryLog(), 'statements with '.$count.' types');
        DB::connection()->disableQueryLog();
    }

    /**
     * @return array<string, array{int}>
     */
    public static function counts(): array
    {
        return ['3 types' => [3], '30 types' => [30]];
    }

    #[Test]
    public function it_refuses_the_anonymous_principal_with_problem_details(): void
    {
        $world = new TypeEditorsWorld;
        app()->instance(QueryPipeline::class, $world->pipeline());

        $response = $this->withHeaders(['Accept' => 'application/json'])->get(self::LIST);

        $response->assertStatus(403)->assertHeader('Content-Type', 'application/problem+json');
        self::assertStringContainsString('"code":"unauthorized"', (string) $response->getContent());
        self::assertStringContainsString('type.list', (string) $response->getContent());
    }

    /**
     * @return TestResponse<Response>
     */
    private function rest(string $path, TransportCredential $credential, string $query = '{}'): TestResponse
    {
        return $this->withHeaders(['Accept' => 'application/json', 'Authorization' => 'Bearer '.$credential->reveal()])
            ->get($path.'?'.http_build_query([RestRoute::QUERY_PARAMETER => $query]));
    }

    private function inertia(string $query, string $document, TransportCredential $credential): string
    {
        $response = $this->withHeaders(['X-Inertia' => 'true', 'Authorization' => 'Bearer '.$credential->reveal()])
            ->get(sprintf('%s/%s/v1?%s', self::INERTIA, $query, http_build_query([RestRoute::QUERY_PARAMETER => $document])));
        $page = json_decode((string) $response->getContent(), false, 512, JSON_THROW_ON_ERROR);
        $props = $page instanceof stdClass && ($page->props ?? null) instanceof stdClass ? $page->props : new stdClass;

        $response->assertOk();
        self::assertNull($props->{InertiaOutcome::PROBLEM_PROP} ?? null);

        return json_encode($props->{InertiaQueryOutcome::RESULT} ?? null, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return array<mixed>
     */
    private function document(TestResponse $response): array
    {
        $response->assertOk();
        $document = json_decode((string) $response->getContent(), true, 64, JSON_THROW_ON_ERROR);

        return is_array($document) ? $document : [];
    }

    /**
     * @param  array<mixed>  $type
     * @return list<string>
     */
    private function paths(array $type): array
    {
        $fields = is_array($type['fields'] ?? null) ? $type['fields'] : [];

        return array_values(array_map(static fn (mixed $field): string => is_array($field) && is_string($field['path'] ?? null) ? $field['path'] : '', $fields));
    }
}
