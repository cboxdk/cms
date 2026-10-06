<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Tests\Rest;

use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Core\Access\Domain\HeldPermissions;
use Cbox\Cms\Core\Identity\Actions\WhoAmIAction;
use Cbox\Cms\Core\Identity\Domain\Dto\ActorMe;
use Cbox\Cms\Core\Identity\Domain\Queries\WhoAmI;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryBinding;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\RestRoute;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeAccessContexts;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeHeldPermissions;
use Cbox\Cms\Core\Tests\Identity\Fakes\FakeOwnActorReader;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryAuthorizer;
use Cbox\Cms\Core\Tests\Reads\Probe\ProbeQueryBinding;
use Cbox\Cms\Core\Tests\Registry\ActionListWorld;
use Cbox\Cms\Core\Tests\Registry\RegistryFixtures;
use Cbox\Cms\Http\Inertia\Domain\InertiaActions;
use Cbox\Cms\Http\Rest\Boundary\RestResponse;
use Cbox\Cms\Http\Rest\RestRoutes;
use Cbox\Cms\Identity\Tests\Login\LocalLoginWorld;
use Cbox\Cms\Panel\Palette\Boundary\PaletteProps;
use Cbox\Cms\Panel\Tests\PanelLogins;
use Cbox\Cms\Tests\Support\SurfaceContract\SurfaceContractCases;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Routing\Router;
use Illuminate\Testing\TestResponse;
use Override;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * action.list on REST and in the panel, for the same person (GUARDRAILS 2.1 and 8, PRD 13.2, 13.4;
 * Sylvester 29 September: the panel and REST stay in parity): the installation's registry,
 * compiled as cms:build compiles it, with REST's routes registered as an application registers
 * them, over the QueryPipeline of the ActionListWorld's fakes, whose credential verifier is the
 * login world's for the panel's session and a PersonTokenVerifier for the person's Bearer token.
 * The person, who holds role.create and grant.list on the root, gets from GET
 * /v1/queries/action.list/v1 the same document the panel's shared prop `palette` carries on every
 * page behind the login: the command they may run, the reads they hold and those every actor may
 * run, and the core's entry of the who-am-I page, and not entry.publish, which they do not hold;
 * a rejected read gives the prop the problem details instead; the login page, before any
 * session, carries no palette; and the anonymous principal gets problem details with unauthorized
 * on REST.
 */
final class ActionListParityTest extends TestCase
{
    use PanelLogins;

    private const string EMAIL = 'nora.vang@example.com';

    private const string TOKEN = 'cms_pat_test_nora_vang_000000000000000000000000';

    private const string PATH = '/v1/queries/action.list/v1';

    private ?CompiledRegistry $registry = null;

    private ?ActionListWorld $world = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $logins = $this->setUpPanelLogins();
        $person = $logins->person(self::EMAIL);
        $this->registry = SurfaceContractCases::installation(app());
        $this->world = new ActionListWorld($this->registry, new PersonTokenVerifier($logins->verifier(), $person->id, self::TOKEN), $logins->clock, $logins->identity);
        $this->world->grant($person->id, ['role.create', 'grant.list']);

        app()->instance(InertiaActions::class, new InertiaActions($this->registry));
        app()->instance(QueryPipeline::class, $this->world->pipeline(more: $this->whoAmI($person->id)));
        app()->instance(RegistryCache::class, $this->world->cache);
        app()->instance(HeldPermissions::class, new FakeHeldPermissions($this->world->permissions, new FakeAccessContexts()->grant($person->id, ClassificationAccess::Internal)));
        $router = app(Router::class);
        RestRoutes::register($router, $this->registry);
        $router->getRoutes()->refreshNameLookups();
    }

    #[Override]
    protected function tearDown(): void
    {
        RegistryFixtures::cleanUp();
        $this->tearDownPanelLogins();
        $this->registry = null;
        $this->world = null;

        parent::tearDown();
    }

    #[Test]
    public function rest_gives_the_person_the_same_entries_as_the_palette_s_prop_on_every_page_behind_the_login(): void
    {
        $rest = $this->rest();
        $document = json_decode((string) $rest->getContent(), true, 32, JSON_THROW_ON_ERROR);

        $rest->assertOk()->assertHeader('Content-Type', 'application/json')->assertHeader('Cache-Control', RestResponse::CACHE_CONTROL);
        self::assertIsArray($document);
        self::assertSame(['action.list@1 query', 'actor.me@1 query', 'grant.list@1 query', 'node.list@1 query', 'role.create@1 command'], $this->listed($document));
        self::assertSame(['cms.account-me'], array_column(is_array($document['navigation'] ?? null) ? $document['navigation'] : [], 'id'));
        self::assertSame(self::PATH, $this->restPath());

        $session = $this->signIn();

        foreach (['/cms', '/cms/account/me'] as $path) {
            $palette = $this->palette($this->withUnencryptedCookie($this->cookieName(), $session)->get($path)->assertOk());

            self::assertSame($document, $palette['result'] ?? null, 'the palette of '.$path);
            self::assertArrayHasKey('rejection', $palette);
            self::assertNull($palette['rejection']);
        }
    }

    #[Test]
    public function the_palette_carries_the_problem_details_of_a_rejected_read(): void
    {
        $world = $this->world ?? self::fail('No world.');
        app()->instance(QueryPipeline::class, $world->pipeline(new FakeQueryAuthorizer('The test refuses every read.')));

        $palette = $this->palette($this->withUnencryptedCookie($this->cookieName(), $this->signIn())->get('/cms')->assertOk());

        self::assertArrayHasKey('result', $palette);
        self::assertNull($palette['result']);
        self::assertSame('unauthorized', is_array($palette['rejection'] ?? null) ? $palette['rejection']['code'] ?? null : null);
        self::assertSame(403, is_array($palette['rejection'] ?? null) ? $palette['rejection']['status'] ?? null : null);
    }

    #[Test]
    public function the_login_page_carries_no_palette_and_the_anonymous_principal_is_refused_on_rest(): void
    {
        $login = $this->visitLogin();
        $page = $this->page($login);
        $props = is_array($page['props'] ?? null) ? $page['props'] : [];

        self::assertArrayNotHasKey(PaletteProps::PROP, $props);

        $response = $this->withHeaders(['Accept' => 'application/json'])->get(self::PATH.'?'.http_build_query([RestRoute::QUERY_PARAMETER => '{}']));

        $response->assertStatus(403)->assertHeader('Content-Type', 'application/problem+json');
        self::assertStringContainsString('"code":"unauthorized"', (string) $response->getContent());
        self::assertStringContainsString('action.list', (string) $response->getContent());
    }

    /**
     * The binding of actor.me the who-am-I page reads beside the palette: the person's own self,
     * an active member of staff without a profile or a grant, which the page shows and this test
     * does not look at.
     *
     * @return array<class-string<Query>, QueryBinding>
     */
    private function whoAmI(ActorId $person): array
    {
        $self = new ActorMe($person, ActorClass::Staff, ActorState::Active, AggregateVersion::first(), null, []);

        return [WhoAmI::class => ProbeQueryBinding::of(new WhoAmIAction(new FakeOwnActorReader($person)->add($self)), 'actor.me', 1)];
    }

    /**
     * The names and kinds of the actions of a decoded result, `name@version kind`.
     *
     * @param  array<array-key, mixed>  $document
     * @return list<string>
     */
    private function listed(array $document): array
    {
        $listed = [];

        foreach (is_array($document['actions'] ?? null) ? $document['actions'] : [] as $action) {
            if (is_array($action)) {
                $listed[] = sprintf('%s@%s %s', is_scalar($action['name'] ?? null) ? $action['name'] : '', is_scalar($action['version'] ?? null) ? $action['version'] : '', is_scalar($action['kind'] ?? null) ? $action['kind'] : '');
            }
        }

        return $listed;
    }

    /**
     * @return TestResponse<Response>
     */
    private function rest(): TestResponse
    {
        return $this->withHeaders(['Accept' => 'application/json', 'Authorization' => 'Bearer '.self::TOKEN])
            ->get($this->restPath().'?'.http_build_query([RestRoute::QUERY_PARAMETER => '{}']));
    }

    /**
     * The path of action.list's REST route in the compiled registry.
     */
    private function restPath(): string
    {
        foreach ($this->registry instanceof CompiledRegistry ? $this->registry->rest : [] as $route) {
            if ($route->name->value === PaletteProps::QUERY) {
                return $route->path;
            }
        }

        self::fail('The registry has no REST route of action.list.');
    }

    /**
     * Signs the person in and returns the value of the session cookie.
     */
    private function signIn(): string
    {
        $this->visitLogin();

        return $this->sessionCookie($this->logIn(self::EMAIL, LocalLoginWorld::PASSWORD))?->getValue() ?? self::fail('No session.');
    }

    /**
     * The prop `palette` of the Inertia page the response rendered, decoded as the browser receives it.
     *
     * @param  TestResponse<Response>  $response
     * @return array<array-key, mixed>
     */
    private function palette(TestResponse $response): array
    {
        $page = $this->page($response);
        $props = is_array($page['props'] ?? null) ? $page['props'] : [];
        $palette = $props[PaletteProps::PROP] ?? null;

        self::assertIsArray($palette, 'The page carries the prop palette.');

        return $palette;
    }

    /**
     * The Inertia page the response rendered, with its props as the browser receives them.
     *
     * @param  TestResponse<Response>  $response
     * @return array<array-key, mixed>
     */
    private function page(TestResponse $response): array
    {
        $page = $response->viewData('page');

        if (! is_array($page)) {
            self::fail('The response rendered no Inertia page.');
        }

        $page['props'] = json_decode(json_encode($page['props'] ?? [], JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);

        return $page;
    }
}
