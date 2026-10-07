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
use Cbox\Cms\Core\Access\Domain\Dto\RoleList;
use Cbox\Cms\Core\Access\Domain\HeldPermissions;
use Cbox\Cms\Core\Codecs\Boundary\Generated\RoleListCodecV1;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeAccessContexts;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeHeldPermissions;
use Cbox\Cms\Core\Tests\Access\Fakes\FakePermissions;
use Cbox\Cms\Core\Tests\Access\ListingWorld;
use Cbox\Cms\Identity\Tests\Login\LocalLoginWorld;
use Cbox\Cms\Panel\Boundary\PanelPages;
use Cbox\Cms\Panel\Contributions\Boundary\ContributionProps;
use Cbox\Cms\Panel\Contributions\Domain\CoreContributions;
use Cbox\Cms\Panel\Contributions\Domain\PointCodecs;
use Cbox\Cms\Panel\Pages\AccessRolesController;
use Cbox\Cms\Panel\Shell\Domain\OwnPage;
use Cbox\Cms\Panel\Tests\Access\AccessPagesWorld;
use Cbox\Cms\Panel\Tests\Contributions\ContributionWorld;
use Cbox\Cms\Panel\Tests\PanelLogins;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Testing\TestResponse;
use Override;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The roles page over HTTP (PRD 5.10, 13.4), in the workbench, which mounts the panel at /cms: a
 * person who signed in and holds role.list gets the page Access/Roles with the result of
 * role.list, read through the QueryPipeline as the person from the session credential, in its
 * props as the result codec wrote it, the page after the role the address names when it names
 * one, and the first page for an address that names no role; a rejected read gives the problem
 * details instead; the core's nav entries to the roles and grants pages are among the
 * contributions the person gets, after the who-am-I page's, and the page is among the pages a
 * contribution may navigate to; the page is never stored; and without a session the panel sends
 * the browser to the login.
 */
final class AccessRolesPageTest extends TestCase
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
    public function it_shows_the_roles_as_the_result_codec_writes_them(): void
    {
        $response = $this->visitSignedIn('/cms/access/roles');
        $page = $this->page($response);
        $props = is_array($page['props'] ?? null) ? $page['props'] : [];

        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        self::assertSame(PanelPages::ACCESS_ROLES, $page['component'] ?? null);
        self::assertSame('/cms/logout', $props['logout'] ?? null);
        self::assertArrayHasKey('rejection', $props);
        self::assertNull($props['rejection']);
        self::assertSame(json_decode(AccessPagesWorld::documents()['role.list'], true, 16, JSON_THROW_ON_ERROR), $props['result'] ?? null);
        self::assertSame(['admin', 'desk'], array_column($this->listOf($props, 'roles'), 'handle'));
    }

    #[Test]
    public function it_reads_the_page_after_the_role_the_address_names_and_the_first_page_for_a_value_that_is_no_id(): void
    {
        $this->visitLogin();
        $session = $this->sessionCookie($this->logIn(self::EMAIL, LocalLoginWorld::PASSWORD))?->getValue() ?? self::fail('No session.');
        $after = $this->props($this->withUnencryptedCookie($this->cookieName(), $session)->get('/cms/access/roles?after='.ListingWorld::ADMIN_ROLE));
        $first = $this->props($this->withUnencryptedCookie($this->cookieName(), $session)->get('/cms/access/roles?after=not-a-role'));

        self::assertSame(['desk'], array_column($this->listOf($after, 'roles'), 'handle'));
        self::assertSame(
            json_decode(new RoleListCodecV1()->encode(new RoleList([ListingWorld::roles()[1]], null), ClassificationAccess::Personal), true, 16, JSON_THROW_ON_ERROR),
            $after['result'] ?? null,
        );
        self::assertSame(['admin', 'desk'], array_column($this->listOf($first, 'roles'), 'handle'));
    }

    #[Test]
    public function it_lists_the_core_s_nav_entries_to_the_access_pages_and_the_page_among_the_pages_a_contribution_may_open(): void
    {
        $props = $this->props($this->visitSignedIn('/cms/access/roles'));
        $cms = $props[ContributionProps::CMS] ?? null;
        $contributions = is_array($cms) && is_array($cms[ContributionProps::CONTRIBUTIONS] ?? null) ? $cms[ContributionProps::CONTRIBUTIONS] : [];
        $nav = [];

        foreach (is_array($contributions['points'] ?? null) ? $contributions['points'] : [] as $point) {
            if (is_array($point) && ($point['point'] ?? null) === 'shell.nav@1') {
                $nav = is_array($point['fills'] ?? null) ? $point['fills'] : [];
            }
        }

        self::assertSame([CoreContributions::ACCOUNT_ME_NAV, CoreContributions::ROLES_NAV, CoreContributions::GRANTS_NAV], array_column($nav, 'id'));
        self::assertSame(['icon' => null, 'label' => 'panel.nav.roles', 'page' => 'access.roles'], $this->at($nav, 1, 'nav'));
        self::assertSame(['icon' => null, 'label' => 'panel.nav.grants', 'page' => 'access.grants'], $this->at($nav, 2, 'nav'));
        self::assertContains(['page' => 'access.roles', 'url' => '/cms/access/roles'], is_array($contributions['pages'] ?? null) ? $contributions['pages'] : []);
        self::assertContains(['page' => 'access.grants', 'url' => '/cms/access/grants'], is_array($contributions['pages'] ?? null) ? $contributions['pages'] : []);
    }

    #[Test]
    public function it_shows_the_problem_details_of_a_rejected_read_instead_of_a_result(): void
    {
        $world = $this->world ?? self::fail('No world.');
        app()->instance(QueryPipeline::class, $world->refusing());

        $response = $this->visitSignedIn('/cms/access/roles');
        $props = $this->props($response);

        $response->assertOk();
        self::assertArrayHasKey('result', $props);
        self::assertNull($props['result']);
        self::assertSame('unauthorized', $this->at($props, 'rejection', 'code'));
        self::assertSame(403, $this->at($props, 'rejection', 'status'));
    }

    #[Test]
    public function it_refuses_the_read_to_a_person_whose_role_does_not_name_role_list(): void
    {
        $logins = $this->logins ?? self::fail('No logins.');
        $reader = $logins->person('eve.editor@example.com');
        $world = new AccessPagesWorld($logins->verifier(), $reader->id, $logins->clock, $logins->identity, ['entry.revise']);
        app()->instance(QueryPipeline::class, $world->pipeline());

        $props = $this->props($this->visitSignedIn('/cms/access/roles', 'eve.editor@example.com'));

        self::assertNull($props['result'] ?? null);
        self::assertSame('unauthorized', $this->at($props, 'rejection', 'code'));
    }

    #[Test]
    public function it_sends_a_browser_without_a_session_to_the_login(): void
    {
        $this->get('/cms/access/roles')->assertStatus(303)->assertRedirect('/cms/login?reason=required');
    }

    #[Test]
    public function the_page_is_served_at_the_route_of_the_own_page_by_its_controller(): void
    {
        $route = app(Router::class)->getRoutes()->getByName(OwnPage::AccessRoles->route()->value);

        self::assertInstanceOf(Route::class, $route);
        self::assertSame('cms/access/roles', $route->uri());
        self::assertSame(AccessRolesController::class, $route->getActionName());
        self::assertSame('role.list@1', OwnPage::AccessRoles->query()?->toString());
        self::assertSame(['role.list@1'], array_map(static fn (CommandRef $query): string => $query->toString(), OwnPage::AccessRoles->queries()));
    }

    /**
     * @return TestResponse<Response>
     */
    private function visitSignedIn(string $path, string $email = self::EMAIL): TestResponse
    {
        $this->visitLogin();
        $session = $this->sessionCookie($this->logIn($email, LocalLoginWorld::PASSWORD))?->getValue() ?? self::fail('No session.');

        return $this->withUnencryptedCookie($this->cookieName(), $session)->get($path);
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
