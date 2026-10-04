<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Feature;

use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Core\Access\Domain\HeldPermissions;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ActorMeCodecV1;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeAccessContexts;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeHeldPermissions;
use Cbox\Cms\Core\Tests\Access\Fakes\FakePermissions;
use Cbox\Cms\Identity\Tests\Login\LocalLoginWorld;
use Cbox\Cms\Panel\Boundary\PanelPages;
use Cbox\Cms\Panel\Contributions\Boundary\ContributionProps;
use Cbox\Cms\Panel\Contributions\Domain\CoreContributions;
use Cbox\Cms\Panel\Contributions\Domain\PointCodecs;
use Cbox\Cms\Panel\Pages\AccountMeController;
use Cbox\Cms\Panel\Shell\Domain\OwnPage;
use Cbox\Cms\Panel\Tests\Account\AccountMeWorld;
use Cbox\Cms\Panel\Tests\Contributions\ContributionWorld;
use Cbox\Cms\Panel\Tests\PanelLogins;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Routing\Router;
use Illuminate\Testing\TestResponse;
use Override;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The who-am-I page over HTTP (PRD 5.16, 13.4), in the workbench, which mounts the panel at /cms:
 * a person who signed in gets the page Account/Me with the result of actor.me, read through the
 * QueryPipeline as the person from the session credential, in its props as the result codec wrote
 * it; a rejected read gives the problem details instead; the core's nav entry to the page is among
 * the contributions the person gets and the page is among the pages a contribution may navigate
 * to; the page is never stored; and without a session the panel sends the browser to the login.
 */
final class AccountMePageTest extends TestCase
{
    use PanelLogins;

    private const string EMAIL = 'jonas.berg@example.com';

    private ?AccountMeWorld $world = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $logins = $this->setUpPanelLogins();
        $person = $logins->person(self::EMAIL);
        $this->world = new AccountMeWorld($logins->verifier(), $person->id, self::EMAIL);
        $registry = ContributionWorld::registry(core: CoreContributions::all());

        app()->instance(CompiledRegistry::class, $registry);
        app()->instance(RegistryCache::class, ContributionWorld::cache($registry));
        app()->instance(PointCodecs::class, ContributionWorld::pointCodecs());
        app()->instance(HeldPermissions::class, new FakeHeldPermissions(new FakePermissions([]), new FakeAccessContexts()->grant($person->id, ClassificationAccess::Public)));
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
    public function it_shows_the_person_their_own_actor_profile_and_grants_as_the_result_codec_writes_them(): void
    {
        $world = $this->world ?? self::fail('No world.');
        $response = $this->visitSignedIn('/cms/account/me');
        $page = $this->page($response);
        $props = is_array($page['props'] ?? null) ? $page['props'] : [];

        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        self::assertSame(PanelPages::ACCOUNT_ME, $page['component'] ?? null);
        self::assertSame('/cms/logout', $props['logout'] ?? null);
        self::assertArrayHasKey('rejection', $props);
        self::assertNull($props['rejection']);
        self::assertSame(
            json_decode(new ActorMeCodecV1()->encode($world->self(), ClassificationAccess::Public), true, 16, JSON_THROW_ON_ERROR),
            $props['result'] ?? null,
        );
        self::assertSame($world->email, $this->at($props, 'result', 'profile', 'email'));
    }

    #[Test]
    public function it_lists_the_core_s_nav_entry_to_the_page_and_the_page_among_the_pages_a_contribution_may_open(): void
    {
        $page = $this->page($this->visitSignedIn('/cms/account/me'));
        $props = is_array($page['props'] ?? null) ? $page['props'] : [];
        $cms = $props[ContributionProps::CMS] ?? null;
        $contributions = json_decode((string) json_encode(is_array($cms) ? $cms[ContributionProps::CONTRIBUTIONS] ?? null : null), true);
        $nav = [];

        foreach (is_array($contributions) && is_array($contributions['points'] ?? null) ? $contributions['points'] : [] as $point) {
            if (is_array($point) && ($point['point'] ?? null) === 'shell.nav@1') {
                $nav = is_array($point['fills'] ?? null) ? $point['fills'] : [];
            }
        }

        self::assertSame([CoreContributions::ACCOUNT_ME_NAV], array_column($nav, 'id'));
        self::assertSame(['icon' => null, 'label' => 'panel.nav.account_me', 'page' => 'account.me'], $this->at($nav, 0, 'nav'));
        self::assertSame('cms', $this->at($nav, 0, 'addon'));
        self::assertContains(['page' => 'account.me', 'url' => '/cms/account/me'], is_array($contributions) && is_array($contributions['pages'] ?? null) ? $contributions['pages'] : []);
    }

    #[Test]
    public function it_shows_the_problem_details_of_a_rejected_read_instead_of_a_result(): void
    {
        $world = $this->world ?? self::fail('No world.');
        app()->instance(QueryPipeline::class, $world->refusing());

        $response = $this->visitSignedIn('/cms/account/me');
        $props = is_array($this->page($response)['props'] ?? null) ? $this->page($response)['props'] : [];

        $response->assertOk();
        self::assertArrayHasKey('result', $props);
        self::assertNull($props['result']);
        self::assertSame('unauthorized', $this->at($props, 'rejection', 'code'));
        self::assertSame(403, $this->at($props, 'rejection', 'status'));
    }

    #[Test]
    public function it_sends_a_browser_without_a_session_to_the_login(): void
    {
        $this->get('/cms/account/me')->assertStatus(303)->assertRedirect('/cms/login?reason=required');
    }

    #[Test]
    public function the_page_is_served_at_the_route_of_the_own_page_by_its_controller(): void
    {
        $route = app(Router::class)->getRoutes()->getByName(OwnPage::AccountMe->route()->value);

        self::assertSame('cms/account/me', $route?->uri());
        self::assertSame(AccountMeController::class, $route?->getActionName());
    }

    /**
     * @return TestResponse<Response>
     */
    private function visitSignedIn(string $path): TestResponse
    {
        $this->visitLogin();
        $session = $this->sessionCookie($this->logIn(self::EMAIL, LocalLoginWorld::PASSWORD))?->getValue() ?? self::fail('No session.');

        return $this->withUnencryptedCookie($this->cookieName(), $session)->get($path);
    }

    /**
     * The Inertia page the response rendered, with its props as the browser receives them: objects
     * as arrays, so a document compares by value.
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
