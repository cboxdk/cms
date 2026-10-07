<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Feature;

use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeRegistryCache;
use Cbox\Cms\Panel\Tests\FixtureBuild;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Testing\TestResponse;
use Override;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Workbench\FixtureAddon\FixtureAddonServiceProvider;

/**
 * The login page's notices over HTTP (PRD 13.4): the page carries, in the prop notices, every
 * LoginNotice to login.notice@1 of the installation's registry, the workbench's fixture addon's
 * among them, each as data alone, its import map naming no addon, because a credential page runs
 * no addon code; a notice the kill switch disables is left out at the next request; and a
 * registry that cannot be read leaves the page with no notice and still answers 200.
 */
final class LoginNoticesPageTest extends TestCase
{
    private ?FixtureBuild $fixture = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = FixtureBuild::write();
        $this->fixture->bind($this->app ?? app());
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->fixture?->remove();
        $this->fixture = null;

        parent::tearDown();
    }

    #[Test]
    public function it_sends_the_fixture_addons_notice_as_data_without_any_addon_in_the_import_map(): void
    {
        $response = $this->get('/cms/login');

        $response->assertOk();
        self::assertSame([[
            'addon' => FixtureAddonServiceProvider::NAMESPACE,
            'id' => FixtureAddonServiceProvider::LOGIN_NOTICE,
            'message' => 'fixtureaddon.login_notice.message',
            'tone' => 'info',
        ]], $this->notices($response));
        self::assertStringNotContainsString('cms-addons/', (string) $response->getContent());
        self::assertStringNotContainsString('/cms/addons/', (string) $response->getContent());
    }

    #[Test]
    public function it_leaves_out_a_notice_the_kill_switch_disables(): void
    {
        app(Repository::class)->set('cbox-cms.panel.disabled', ['contributions' => [FixtureAddonServiceProvider::LOGIN_NOTICE]]);

        $response = $this->get('/cms/login');

        $response->assertOk();
        self::assertSame([], $this->notices($response));
    }

    #[Test]
    public function it_shows_the_login_form_without_notices_when_the_registry_cannot_be_read(): void
    {
        app()->instance(RegistryCache::class, new FakeRegistryCache);

        $response = $this->get('/cms/login');

        $response->assertOk();
        self::assertSame([], $this->notices($response));
    }

    /**
     * The notices prop of the login page the root view rendered.
     *
     * @param  TestResponse<Response>  $response
     * @return list<array<array-key, mixed>>
     */
    private function notices(TestResponse $response): array
    {
        // The props are objects as Inertia holds them; read back through JSON, as the page gets them.
        $page = json_decode(json_encode($response->viewData('page'), JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
        $props = is_array($page) && is_array($page['props'] ?? null) ? $page['props'] : self::fail('The response rendered no Inertia page.');
        $notices = $props['notices'] ?? null;
        self::assertIsArray($notices);

        $listed = [];

        foreach ($notices as $notice) {
            self::assertIsArray($notice);
            $listed[] = $notice;
        }

        return $listed;
    }
}
