<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Postgres;

use Cbox\Cms\Panel\Boundary\PanelPages;
use Cbox\Cms\Panel\Contributions\Boundary\ContributionProps;
use Cbox\Cms\Panel\Tests\Contributions\ContributionWorld;
use Cbox\Cms\Panel\Tests\Contributions\ShellWorld;
use Cbox\Cms\Panel\Tests\PanelSignIn;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Testing\TestResponse;
use Override;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * An addon's page in the panel (PRD 13.4, section 3.4 of the panel extension architecture), on
 * real Postgres and Valkey as the app role over ShellWorld, signed in through the panel's login
 * form: /cms/x/tally/board renders the page Addon with the page's id and addon for a member of
 * staff who holds the page's permission, with the page among the pages a contribution may
 * navigate to and its nav entry in the shell, and the page's data, the result of its query run as
 * the viewer, as the deferred prop of its addon, equal to what GET /v1/queries/tally.board/v1
 * answers the same viewer over REST: a service actor with a service credential, the one kind of
 * viewer that reaches REST in part 1 of B1, served the page behind the credential by ShellWorld. A
 * path no addon has, and the page for a viewer who may not
 * open it, are the panel's page for a path it does not have, with 404.
 */
final class AddonPageTest extends TestCase
{
    use PanelSignIn;
    use RealPostgres;

    private const string BOARD = '/cms/x/tally/'.ContributionWorld::BOARD_PATH;

    private ?ShellWorld $shell = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $shell = new ShellWorld($this->app ?? self::fail('No application.'));
        $this->shell = $shell;
        $this->giveLocalAccount($shell->auditor, ShellWorld::AUDITOR_EMAIL);
        $this->giveLocalAccount($shell->viewer, ShellWorld::VIEWER_EMAIL);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->shell?->cleanUp();
        $this->shell = null;

        parent::tearDown();
    }

    #[Test]
    public function it_renders_the_addon_s_page_for_a_viewer_who_may_open_it_with_its_nav_entry_and_among_the_pages(): void
    {
        $session = $this->signInAs(ShellWorld::AUDITOR_EMAIL);
        $page = $this->visitPanel($session, self::BOARD)->assertOk();

        self::assertSame(PanelPages::ADDON, $page->json('component'));
        self::assertSame(['addon' => 'tally', 'page' => ContributionWorld::BOARD], array_intersect_key((array) $page->json('props'), ['addon' => true, 'page' => true]));
        self::assertSame(PanelPages::PRIVATE_CACHE_CONTROL, $page->headers->get('Cache-Control'));

        $contributions = (array) $page->json('props.'.ContributionProps::CMS.'.'.ContributionProps::CONTRIBUTIONS);

        self::assertSame([['page' => 'home', 'url' => '/cms'], ['page' => ContributionWorld::BOARD, 'url' => self::BOARD]], $contributions['pages'] ?? null);
        self::assertSame(['shell.nav@1', 'shell.page@1', 'shell.user-menu@1'], array_keys($this->pointsOf($page)));
        self::assertSame(['icon' => 'inbox', 'label' => 'tally.nav.board', 'page' => ContributionWorld::BOARD], $this->firstFill($page, 'shell.nav@1')['nav'] ?? null);
        self::assertTrue($this->firstFill($page, 'shell.page@1')['data'] ?? null);
        self::assertSame(['tally' => ['ext.tally']], $page->json('deferredProps'));
    }

    #[Test]
    public function the_page_s_data_equals_what_rest_answers_the_same_viewer(): void
    {
        $shell = $this->shell ?? self::fail('No world.');
        $headers = ['Authorization' => 'Bearer '.$shell->serviceCredential->reveal()];

        $page = $this->withHeaders([...$headers, 'X-Inertia' => 'true'])->get(ShellWorld::SERVICE_PATH.'/tally/'.ContributionWorld::BOARD_PATH)->assertOk();
        $data = $this->withHeaders([...$headers, 'X-Inertia' => 'true', 'X-Inertia-Partial-Component' => PanelPages::ADDON, 'X-Inertia-Partial-Data' => 'ext.tally'])
            ->get(ShellWorld::SERVICE_PATH.'/tally/'.ContributionWorld::BOARD_PATH)
            ->assertOk()
            ->json('props.ext.tally');
        $rest = $this->withHeaders($headers)->getJson('/v1/queries/tally.board/v1')->assertOk();

        self::assertSame(PanelPages::ADDON, $page->json('component'));
        self::assertSame(ContributionWorld::BOARD, $page->json('props.page'));
        self::assertSame([ContributionWorld::BOARD], array_keys((array) $data));
        self::assertSame($rest->json(), ((array) $data)[ContributionWorld::BOARD]);
        self::assertSame(['count', 'owner', 'summary'], array_keys((array) $rest->json()), 'The viewer reads confidential and the addon reads confidential, so the owner is in both.');
    }

    #[Test]
    public function the_start_page_lists_the_nav_entry_and_the_action_of_the_shell_without_running_the_page_s_query(): void
    {
        $session = $this->signInAs(ShellWorld::AUDITOR_EMAIL);
        $home = $this->visitPanel($session, '/cms')->assertOk();
        $action = $this->firstFill($home, 'shell.user-menu@1')['action'] ?? null;

        self::assertSame(PanelPages::HOME, $home->json('component'));
        self::assertSame(ContributionWorld::BOARD_LINK, $this->firstFill($home, 'shell.nav@1')['id'] ?? null);
        self::assertSame(ContributionWorld::ADD, $this->firstFill($home, 'shell.user-menu@1')['id'] ?? null);
        self::assertSame('tally.add@1', is_array($action) ? $action['command'] ?? null : null);
        self::assertFalse($this->firstFill($home, 'shell.page@1')['data'] ?? null, 'The page is listed, and its query does not run on the start page.');
        self::assertNull($home->json('deferredProps'));
    }

    #[Test]
    public function a_path_no_addon_has_and_a_page_the_viewer_may_not_open_are_the_page_for_a_path_the_panel_does_not_have(): void
    {
        $auditor = $this->signInAs(ShellWorld::AUDITOR_EMAIL);
        $viewer = $this->signInAs(ShellWorld::VIEWER_EMAIL);

        foreach ([[$auditor, '/cms/x/tally/nowhere'], [$auditor, '/cms/x/other/board'], [$viewer, self::BOARD]] as [$session, $path]) {
            $page = $this->visitPanel($session, $path)->assertNotFound();

            self::assertSame(PanelPages::NOT_FOUND, $page->json('component'), $path);
        }

        $contributions = (array) $this->visitPanel($viewer, '/cms')->assertOk()->json('props.'.ContributionProps::CMS.'.'.ContributionProps::CONTRIBUTIONS);

        self::assertSame([['page' => 'home', 'url' => '/cms']], $contributions['pages'] ?? null);
        self::assertSame([], $contributions['points'] ?? null, 'The viewer holds no permission of the shell\'s contributions, so none is listed, the nav entry included.');
    }

    /**
     * The page's points by id.
     *
     * @param  TestResponse<Response>  $page
     * @return array<string, array<array-key, mixed>>
     */
    private function pointsOf(TestResponse $page): array
    {
        $contributions = (array) $page->json('props.'.ContributionProps::CMS.'.'.ContributionProps::CONTRIBUTIONS);
        $points = [];

        foreach (is_array($contributions['points'] ?? null) ? $contributions['points'] : [] as $point) {
            if (is_array($point) && is_string($point['point'] ?? null)) {
                $points[$point['point']] = $point;
            }
        }

        return $points;
    }

    /**
     * The first fill of a point of the page.
     *
     * @param  TestResponse<Response>  $page
     * @return array<array-key, mixed>
     */
    private function firstFill(TestResponse $page, string $point): array
    {
        $fills = $this->pointsOf($page)[$point]['fills'] ?? null;
        $first = is_array($fills) ? $fills[0] ?? null : null;

        return is_array($first) ? $first : [];
    }
}
