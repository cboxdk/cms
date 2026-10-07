<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Postgres;

use Cbox\Cms\Panel\Access\Domain\AccessGrants;
use Cbox\Cms\Panel\Boundary\PanelPages;
use Cbox\Cms\Panel\Contributions\Boundary\ContributionProps;
use Cbox\Cms\Panel\Contributions\Domain\CoreContributions;
use Cbox\Cms\Panel\Tests\Access\AccessWorld;
use Cbox\Cms\Panel\Tests\PanelSignIn;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Testing\TestResponse;
use Override;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The roles and grants pages in the panel (PRD 5.10, 13.4), on real Postgres and Valkey as the app
 * role over AccessWorld, signed in through the panel's login form: /cms/access/roles renders the
 * page Access/Roles with the roles of the installation as role.list reads them for the
 * administrator, /cms/access/grants the page Access/Grants with the grants as grant.list reads
 * them, the administrator's own with its profile, the locales of the configured sites and the
 * nav entries of both pages, and a partial reload of the pickers reads actor.list, role.list and
 * node.list as the administrator, with every profile, because its classification access is
 * personal. Each page's result equals what GET /v1/queries/<query>/v1 answers the same viewer
 * over REST: a service actor granted as the administrator is, with a service credential, served
 * the pages behind the credential by AccessWorld. A member of staff without a grant gets neither
 * nav entry and a rejected read with unauthorized on the roles page, never a dead end.
 */
final class AccessPagesTest extends TestCase
{
    use PanelSignIn;
    use RealPostgres;

    private ?AccessWorld $world = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $world = new AccessWorld($this->app ?? self::fail('No application.'));
        $this->world = $world;
        $this->giveLocalAccount($world->admin, AccessWorld::ADMIN_EMAIL);
        $this->giveLocalAccount($world->ole, AccessWorld::OLE_EMAIL);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->world?->cleanUp();
        $this->world = null;

        parent::tearDown();
    }

    #[Test]
    public function the_roles_page_lists_the_roles_as_role_list_reads_them_for_the_administrator(): void
    {
        $world = $this->world();
        $session = $this->signInAs(AccessWorld::ADMIN_EMAIL);
        $page = $this->visitPanel($session, '/cms/access/roles')->assertOk();

        self::assertSame(PanelPages::ACCESS_ROLES, $page->json('component'));
        self::assertSame(PanelPages::PRIVATE_CACHE_CONTROL, $page->headers->get('Cache-Control'));
        self::assertNull($page->json('props.rejection'));
        self::assertSame([AccessWorld::ADMIN_ROLE, AccessWorld::EDITOR, AccessWorld::PUBLISHER, AccessWorld::ADMINS], array_column($this->rows($page, 'roles'), 'handle'));
        self::assertSame([$world->editor->toString()], array_column(array_filter($this->rows($page, 'roles'), static fn (array $role): bool => $role['handle'] === AccessWorld::EDITOR), 'id'));
        self::assertSame(['entry.revise'], $this->rows($page, 'roles')[1]['permissions'] ?? null);
        self::assertNull($page->json('props.result.next'));
        self::assertSame([CoreContributions::ACCOUNT_ME_NAV, CoreContributions::ROLES_NAV, CoreContributions::GRANTS_NAV], $this->navIds($page));
    }

    #[Test]
    public function the_grants_page_lists_the_grants_with_their_profiles_and_the_locales_of_the_sites(): void
    {
        $world = $this->world();
        $session = $this->signInAs(AccessWorld::ADMIN_EMAIL);
        $page = $this->visitPanel($session, '/cms/access/grants')->assertOk();
        $grants = $this->rows($page, 'grants');

        self::assertSame(PanelPages::ACCESS_GRANTS, $page->json('component'));
        self::assertSame(['da', 'en'], $page->json('props.locales'));
        self::assertNull($page->json('props.rejection'));
        self::assertSame([$world->adminGrant->toString()], array_column(array_filter($grants, static fn (array $grant): bool => $grant['actor'] === $world->admin->toString()), 'id'));
        self::assertSame(['display_name' => AccessWorld::ADMIN_NAME, 'email' => AccessWorld::ADMIN_EMAIL], $grants[0]['profile'] ?? null);
        self::assertSame(AccessWorld::ADMIN_ROLE, $grants[0]['role_handle'] ?? null);
        self::assertSame('access', $grants[0]['node_label'] ?? null);
        self::assertArrayNotHasKey(AccessGrants::PICKERS, (array) $page->json('props'));
    }

    #[Test]
    public function a_partial_reload_reads_the_pickers_as_the_administrator_with_every_profile(): void
    {
        $world = $this->world();
        $session = $this->signInAs(AccessWorld::ADMIN_EMAIL);
        $reload = $this->visitPanel($session, '/cms/access/grants', PanelPages::ACCESS_GRANTS, [AccessGrants::PICKERS])->assertOk();
        $pickers = (array) $reload->json('props.'.AccessGrants::PICKERS);

        self::assertSame(['actors', 'nodes', 'roles'], array_keys($pickers));
        self::assertNull($reload->json('props.'.AccessGrants::PICKERS.'.actors.rejection'));

        $actors = (array) $reload->json('props.'.AccessGrants::PICKERS.'.actors.result.actors');
        $names = array_map(static fn (mixed $actor): mixed => is_array($actor) && is_array($actor['profile'] ?? null) ? $actor['profile']['display_name'] ?? null : null, $actors);

        self::assertSame([AccessWorld::ADMIN_NAME, AccessWorld::OLE_NAME], array_values(array_filter($names, is_string(...))));
        self::assertSame([AccessWorld::ADMIN_ROLE, AccessWorld::EDITOR, AccessWorld::PUBLISHER, AccessWorld::ADMINS], array_column((array) $reload->json('props.'.AccessGrants::PICKERS.'.roles.result.roles'), 'handle'));
        self::assertSame([$world->root->toString(), $world->section->toString()], array_column((array) $reload->json('props.'.AccessGrants::PICKERS.'.nodes.result.nodes'), 'id'));
        self::assertSame('access', $reload->json('props.'.AccessGrants::PICKERS.'.nodes.result.nodes.0.label'));
    }

    #[Test]
    public function each_page_s_result_equals_what_rest_answers_the_same_viewer(): void
    {
        $world = $this->world();
        $bearer = ['Authorization' => 'Bearer '.$world->serviceCredential->reveal()];

        foreach (['roles' => 'role.list', 'grants' => 'grant.list'] as $path => $query) {
            $page = $this->withHeaders($bearer)->get(AccessWorld::SERVICE_PATH.'/'.$path)->assertOk();
            $rest = $this->withHeaders($bearer)->get('/v1/queries/'.$query.'/v1')->assertOk();
            $rendered = $page->viewData('page');
            $props = is_array($rendered) && is_array($rendered['props'] ?? null) ? $rendered['props'] : self::fail('The page rendered no Inertia props.');

            self::assertArrayHasKey('rejection', $props, $query);
            self::assertNull($props['rejection'], $query);
            self::assertSame(
                json_decode((string) $rest->getContent(), true, 16, JSON_THROW_ON_ERROR),
                json_decode(json_encode($props['result'] ?? null, JSON_THROW_ON_ERROR), true, 16, JSON_THROW_ON_ERROR),
                sprintf('The %s page shows what REST answers with %s.', $path, $query),
            );
        }
    }

    #[Test]
    public function a_member_of_staff_without_a_grant_gets_neither_nav_entry_and_a_rejected_read(): void
    {
        $session = $this->signInAs(AccessWorld::OLE_EMAIL);
        $page = $this->visitPanel($session, '/cms/access/roles')->assertOk();

        self::assertSame(PanelPages::ACCESS_ROLES, $page->json('component'));
        self::assertNull($page->json('props.result'));
        self::assertSame('unauthorized', $page->json('props.rejection.code'));
        self::assertSame([CoreContributions::ACCOUNT_ME_NAV], $this->navIds($page));
    }

    private function world(): AccessWorld
    {
        return $this->world ?? self::fail('No world.');
    }

    /**
     * The rows of the result's list under the key.
     *
     * @param  TestResponse<Response>  $page
     * @return list<array<array-key, mixed>>
     */
    private function rows(TestResponse $page, string $key): array
    {
        $rows = $page->json('props.result.'.$key);

        return is_array($rows) ? array_values(array_filter($rows, is_array(...))) : [];
    }

    /**
     * The ids of the nav entries the page sent, in render order.
     *
     * @param  TestResponse<Response>  $page
     * @return list<string>
     */
    private function navIds(TestResponse $page): array
    {
        $points = $page->json('props.'.ContributionProps::CMS.'.'.ContributionProps::CONTRIBUTIONS.'.points');

        foreach (is_array($points) ? $points : [] as $point) {
            if (is_array($point) && ($point['point'] ?? null) === 'shell.nav@1') {
                return array_values(array_filter(array_column(is_array($point['fills'] ?? null) ? $point['fills'] : [], 'id'), is_string(...)));
            }
        }

        return [];
    }
}
