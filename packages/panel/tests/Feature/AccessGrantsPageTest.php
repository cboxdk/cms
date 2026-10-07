<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Feature;

use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Core\Access\Domain\Dto\Grant;
use Cbox\Cms\Core\Access\Domain\HeldPermissions;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Reads\Domain\UnknownQuery;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Structure\Domain\Queries\ListNodes;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeAccessContexts;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeHeldPermissions;
use Cbox\Cms\Core\Tests\Access\Fakes\FakePermissions;
use Cbox\Cms\Core\Tests\Access\ListingWorld;
use Cbox\Cms\Identity\Tests\Login\LocalLoginWorld;
use Cbox\Cms\Panel\Access\Domain\AccessGrants;
use Cbox\Cms\Panel\Boundary\PanelPages;
use Cbox\Cms\Panel\Contributions\Domain\CoreContributions;
use Cbox\Cms\Panel\Contributions\Domain\PointCodecs;
use Cbox\Cms\Panel\Domain\Dto\PanelBuild;
use Cbox\Cms\Panel\Pages\AccessGrantsController;
use Cbox\Cms\Panel\Shell\Domain\OwnPage;
use Cbox\Cms\Panel\Tests\Access\AccessPagesWorld;
use Cbox\Cms\Panel\Tests\Contributions\ContributionWorld;
use Cbox\Cms\Panel\Tests\PanelLogins;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use Override;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The grants page over HTTP (PRD 5.10, 13.4), in the workbench, which mounts the panel at /cms: a
 * person who signed in and holds grant.list gets the page Access/Grants with the result of
 * grant.list, read through the QueryPipeline as the person from the session credential, in its
 * props as the result codec wrote it, the locales of the configured sites, and no pickers; the
 * page after the grant the address names when it names one; the pickers, the reads of actor.list,
 * role.list and node.list as the person, only on a partial reload that asks for the optional prop,
 * each as its result codec wrote it, and a picker whose read the pipeline cannot make with neither
 * result nor rejection, reported, while the other pickers answer; a rejected read gives the
 * problem details instead; the page is never stored; and without a session the panel sends the
 * browser to the login.
 */
final class AccessGrantsPageTest extends TestCase
{
    use PanelLogins;

    private const string EMAIL = 'ada.admin@example.com';

    private ?AccessPagesWorld $world = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $logins = $this->setUpPanelLogins();
        $person = $logins->person(self::EMAIL);
        $this->world = new AccessPagesWorld($logins->verifier(), $person->id, $logins->clock, $logins->identity);
        $registry = ContributionWorld::registry(core: CoreContributions::all());

        app()->instance(CompiledRegistry::class, $registry);
        app()->instance(RegistryCache::class, ContributionWorld::cache($registry));
        app()->instance(PointCodecs::class, ContributionWorld::pointCodecs());
        app()->instance(HeldPermissions::class, new FakeHeldPermissions(
            new FakePermissions([])->grant(
                $person->id,
                new Grant(RoleId::fromString(ListingWorld::ADMIN_ROLE), ClassificationAccess::Personal, new NodePath('a1'), GrantEffect::Allow),
                array_map(static fn (string $name): CommandName => new CommandName($name), AccessPagesWorld::ADMINISTRATION),
            ),
            new FakeAccessContexts()->grant($person->id, ClassificationAccess::Personal),
        ));
        app()->instance(QueryPipeline::class, $this->world->pipeline());
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->tearDownPanelLogins();
        $this->world = null;

        parent::tearDown();
    }

    #[Test]
    public function it_shows_the_grants_as_the_result_codec_writes_them_with_the_locales_of_the_sites_and_without_the_pickers(): void
    {
        $response = $this->visitSignedIn('/cms/access/grants');
        $page = $this->page($response);
        $props = is_array($page['props'] ?? null) ? $page['props'] : [];

        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        self::assertSame(PanelPages::ACCESS_GRANTS, $page['component'] ?? null);
        self::assertSame('/cms/logout', $props['logout'] ?? null);
        self::assertSame(['da', 'en'], $props['locales'] ?? null);
        self::assertArrayHasKey('rejection', $props);
        self::assertNull($props['rejection']);
        self::assertSame(json_decode(AccessPagesWorld::documents()['grant.list'], true, 16, JSON_THROW_ON_ERROR), $props['result'] ?? null);
        self::assertSame([ListingWorld::GRANT_ADMIN, ListingWorld::GRANT_EDITOR, ListingWorld::GRANT_BOB, ListingWorld::GRANT_DENIED], array_column($this->listOf($props, 'grants'), 'id'));
        self::assertArrayNotHasKey(AccessGrants::PICKERS, $props);
    }

    #[Test]
    public function it_reads_the_page_after_the_grant_the_address_names(): void
    {
        $props = $this->props($this->visitSignedIn('/cms/access/grants?after='.ListingWorld::GRANT_EDITOR));

        self::assertSame([ListingWorld::GRANT_BOB, ListingWorld::GRANT_DENIED], array_column($this->listOf($props, 'grants'), 'id'));
    }

    #[Test]
    public function it_reads_the_pickers_as_the_person_only_on_a_partial_reload_that_asks_for_them(): void
    {
        $session = $this->signedInSession();
        $pickers = $this->pickers($this->reloadPickers($session));
        $documents = AccessPagesWorld::documents();

        self::assertSame(json_decode($documents['actor.list'], true, 16, JSON_THROW_ON_ERROR), $this->at($pickers, 'actors', 'result'));
        self::assertNull($this->at($pickers, 'actors', 'rejection'));
        self::assertSame(json_decode($documents['role.list'], true, 16, JSON_THROW_ON_ERROR), $this->at($pickers, 'roles', 'result'));
        self::assertSame(json_decode($documents['node.list'], true, 16, JSON_THROW_ON_ERROR), $this->at($pickers, 'nodes', 'result'));
        $actors = $this->at($pickers, 'actors', 'result', 'actors');
        self::assertSame(['Ada Admin', 'Eve Editor'], array_values(array_filter(array_map(static fn (mixed $actor): mixed => is_array($actor) && is_array($actor['profile'] ?? null) ? $actor['profile']['display_name'] ?? null : null, is_array($actors) ? $actors : []), is_string(...))));
    }

    #[Test]
    public function a_picker_whose_read_the_pipeline_cannot_make_carries_neither_result_nor_rejection_and_is_reported_while_the_others_answer(): void
    {
        $world = $this->world ?? self::fail('No world.');
        Exceptions::fake();
        app()->instance(QueryPipeline::class, $world->without([ListNodes::class]));

        $pickers = $this->pickers($this->reloadPickers($this->signedInSession()));

        self::assertSame(['rejection' => null, 'result' => null], $this->at($pickers, 'nodes'));
        self::assertNotNull($this->at($pickers, 'actors', 'result'));
        self::assertNotNull($this->at($pickers, 'roles', 'result'));
        Exceptions::assertReported(UnknownQuery::class);
    }

    #[Test]
    public function a_picker_the_person_may_not_read_carries_the_problem_details_of_its_rejection(): void
    {
        $logins = $this->logins ?? self::fail('No logins.');
        $reader = $logins->person('eve.editor@example.com');
        $world = new AccessPagesWorld($logins->verifier(), $reader->id, $logins->clock, $logins->identity, ['grant.list', 'grant.assign']);
        app()->instance(QueryPipeline::class, $world->pipeline());

        $pickers = $this->pickers($this->reloadPickers($this->signedInSession('eve.editor@example.com')));

        self::assertSame('unauthorized', $this->at($pickers, 'actors', 'rejection', 'code'));
        self::assertNull($this->at($pickers, 'actors', 'result'));
        self::assertSame('unauthorized', $this->at($pickers, 'roles', 'rejection', 'code'));
        self::assertNotNull($this->at($pickers, 'nodes', 'result'), 'node.list is a read every actor may make.');
    }

    #[Test]
    public function it_shows_the_problem_details_of_a_rejected_read_instead_of_a_result(): void
    {
        $world = $this->world ?? self::fail('No world.');
        app()->instance(QueryPipeline::class, $world->refusing());

        $response = $this->visitSignedIn('/cms/access/grants');
        $props = $this->props($response);

        $response->assertOk();
        self::assertNull($props['result'] ?? null);
        self::assertSame('unauthorized', $this->at($props, 'rejection', 'code'));
        self::assertSame(403, $this->at($props, 'rejection', 'status'));
    }

    #[Test]
    public function it_sends_a_browser_without_a_session_to_the_login(): void
    {
        $this->get('/cms/access/grants')->assertStatus(303)->assertRedirect('/cms/login?reason=required');
    }

    #[Test]
    public function the_page_is_served_at_the_route_of_the_own_page_by_its_controller_and_reads_the_pickers_queries_too(): void
    {
        $route = app(Router::class)->getRoutes()->getByName(OwnPage::AccessGrants->route()->value);

        self::assertInstanceOf(Route::class, $route);
        self::assertSame('cms/access/grants', $route->uri());
        self::assertSame(AccessGrantsController::class, $route->getActionName());
        self::assertSame('grant.list@1', OwnPage::AccessGrants->query()?->toString());
        self::assertSame(['grant.list@1', 'actor.list@1', 'role.list@1', 'node.list@1'], array_map(static fn (CommandRef $query): string => $query->toString(), OwnPage::AccessGrants->queries()));
    }

    /**
     * Signs in and gives the session cookie's value.
     */
    private function signedInSession(string $email = self::EMAIL): string
    {
        $this->visitLogin();

        return $this->sessionCookie($this->logIn($email, LocalLoginWorld::PASSWORD))?->getValue() ?? self::fail('No session.');
    }

    /**
     * @return TestResponse<Response>
     */
    private function visitSignedIn(string $path, string $email = self::EMAIL): TestResponse
    {
        return $this->withUnencryptedCookie($this->cookieName(), $this->signedInSession($email))->get($path);
    }

    /**
     * The partial reload of the pickers, as the page asks for them when its form opens.
     *
     * @return TestResponse<Response>
     */
    private function reloadPickers(string $session): TestResponse
    {
        return $this->withUnencryptedCookie($this->cookieName(), $session)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => app(PanelBuild::class)->version,
                'X-Inertia-Partial-Component' => PanelPages::ACCESS_GRANTS,
                'X-Inertia-Partial-Data' => AccessGrants::PICKERS,
            ])
            ->get('/cms/access/grants');
    }

    /**
     * The pickers prop of a partial reload's JSON page, as the browser receives it.
     *
     * @param  TestResponse<Response>  $response
     * @return array<array-key, mixed>
     */
    private function pickers(TestResponse $response): array
    {
        $response->assertOk();
        $props = $response->json('props');
        $pickers = is_array($props) ? ($props[AccessGrants::PICKERS] ?? null) : null;

        self::assertIsArray($pickers, 'The partial reload carries the pickers.');
        self::assertSame(['actors', 'nodes', 'roles'], array_keys($pickers));

        return $pickers;
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

    /**
     * @param  TestResponse<Response>  $response
     * @return array<array-key, mixed>
     */
    private function props(TestResponse $response): array
    {
        $props = $this->page($response)['props'] ?? null;

        return is_array($props) ? $props : [];
    }

    /**
     * The rows of the result's list under the key, as arrays.
     *
     * @param  array<array-key, mixed>  $props
     * @return list<array<array-key, mixed>>
     */
    private function listOf(array $props, string $key): array
    {
        $rows = $this->at($props, 'result', $key);

        return is_array($rows) ? array_values(array_filter($rows, is_array(...))) : [];
    }

    /**
     * The value at the keys of a decoded document, or null where there is none.
     *
     * @param  array<array-key, mixed>  $document
     */
    private function at(array $document, string|int ...$keys): mixed
    {
        $value = $document;

        foreach ($keys as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }

        return $value;
    }
}
