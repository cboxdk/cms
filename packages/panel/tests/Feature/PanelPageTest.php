<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Feature;

use Cbox\Cms\Panel\Boundary\ViteManifest;
use Cbox\Cms\Panel\Domain\ContentSecurityPolicy;
use Cbox\Cms\Panel\Domain\CspNonce;
use Cbox\Cms\Panel\Domain\Dto\PanelBuild;
use Cbox\Cms\Panel\Domain\PanelBuildUnavailable;
use Cbox\Cms\Panel\Middleware\SendContentSecurityPolicy;
use Cbox\Cms\Panel\PanelRoutes;
use Cbox\Cms\Panel\Tests\FixtureBuild;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Testing\TestResponse;
use Override;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The panel's pages over HTTP (PRD 13.4, GUARDRAILS 6), in the workbench, which mounts the panel
 * at /cms: every page carries a strict Content-Security-Policy with a nonce of its own, the root
 * view puts that nonce on the build's script, stylesheets and module preloads and on the
 * csp-nonce meta element, and a path the panel does not have is the page Errors/NotFound with 404.
 */
final class PanelPageTest extends TestCase
{
    private ?FixtureBuild $fixture = null;

    private ?PanelBuild $build = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = FixtureBuild::write();
        $this->build = $this->fixture->bind($this->app ?? app());
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->fixture?->remove();
        $this->fixture = null;
        $this->build = null;

        parent::tearDown();
    }

    #[Test]
    public function it_answers_a_path_the_panel_does_not_have_with_the_page_not_found_and_404_in_the_root_view(): void
    {
        $response = $this->get('/cms/does/not/exist');

        $response->assertNotFound()->assertViewIs('cms-panel::app')->assertSeeHtml('<html lang="en">')->assertSeeHtml('<title inertia>Cbox CMS</title>');

        $page = $this->page($response);

        self::assertSame('Errors/NotFound', $page['component'] ?? null);
        self::assertSame(['home' => '/cms'], array_intersect_key(is_array($page['props'] ?? null) ? $page['props'] : [], ['home' => true]));
        self::assertSame('/cms/does/not/exist', $page['url'] ?? null);
        self::assertSame($this->build()->version, $page['version'] ?? null);
    }

    #[Test]
    public function it_sends_a_strict_policy_whose_nonce_is_on_the_script_the_stylesheets_the_preloads_and_the_meta_element_and_on_nothing_else(): void
    {
        $response = $this->get('/cms/anywhere');
        $nonce = $this->nonce($response);
        $html = (string) $response->getContent();

        self::assertSame(ContentSecurityPolicy::header(new CspNonce($nonce)), $response->headers->get(ContentSecurityPolicy::HEADER));
        self::assertStringContainsString('<meta property="csp-nonce" nonce="'.$nonce.'">', $html);
        self::assertStringContainsString('<script type="module" src="/cms/build/assets/app-1a2b3c.js" nonce="'.$nonce.'"></script>', $html);
        self::assertStringContainsString('<link rel="stylesheet" href="/cms/build/assets/shared-3c4d5e.css" nonce="'.$nonce.'">', $html);
        self::assertStringContainsString('<link rel="stylesheet" href="/cms/build/assets/app-7a8b9c.css" nonce="'.$nonce.'">', $html);
        self::assertStringContainsString('<link rel="modulepreload" href="/cms/build/assets/shared-4d5e6f.js" nonce="'.$nonce.'">', $html);
        self::assertSame(5, substr_count($html, 'nonce="'.$nonce.'"'));
        self::assertSame(0, preg_match_all('/<script(?![^>]*type="(?:module|application\/json)")/', $html));
    }

    #[Test]
    public function it_gives_every_response_a_nonce_of_its_own(): void
    {
        $nonces = array_map(fn (): string => $this->nonce($this->get('/cms/again')), range(1, 5));

        self::assertCount(5, array_unique($nonces));
    }

    #[Test]
    public function it_answers_an_inertia_visit_with_the_page_as_json_its_policy_and_the_builds_version(): void
    {
        $response = $this->get('/cms/missing', ['X-Inertia' => 'true', 'X-Inertia-Version' => $this->build()->version]);

        $response->assertNotFound()
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'Errors/NotFound')
            ->assertJsonPath('props.home', '/cms');

        self::assertMatchesRegularExpression(CspNonce::PATTERN, $this->nonce($response));
    }

    #[Test]
    public function it_makes_a_browser_holding_a_page_of_another_build_reload_it_in_full(): void
    {
        $this->get('/cms/missing', ['X-Inertia' => 'true', 'X-Inertia-Version' => str_repeat('0', 64)])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location');
    }

    #[Test]
    public function it_mounts_the_panel_below_the_prefix_an_application_names_and_links_back_to_its_start(): void
    {
        $router = app(Router::class);
        $router->group(['middleware' => 'web'], static fn () => PanelRoutes::register(app(Registrar::class), '/admin/panel/'));
        $router->getRoutes()->refreshNameLookups();

        $response = $this->get('/admin/panel/nowhere')->assertNotFound();
        $props = $this->page($response)['props'] ?? null;

        self::assertSame('/admin/panel', is_array($props) ? $props['home'] ?? null : null);
        self::assertMatchesRegularExpression(CspNonce::PATTERN, $this->nonce($response));
    }

    #[Test]
    public function it_runs_every_panel_page_behind_the_content_security_policy_and_only_the_builds_files_without_it(): void
    {
        $panelRoutes = array_values(array_filter(
            app(Router::class)->getRoutes()->getRoutes(),
            static fn (Route $route): bool => str_starts_with($route->uri(), 'cms/') || $route->uri() === 'cms',
        ));
        $names = array_map(static fn (Route $route): ?string => $route->getName(), $panelRoutes);

        self::assertContains(PanelRoutes::ASSET, $names);
        self::assertContains(PanelRoutes::NOT_FOUND, $names);

        foreach ($panelRoutes as $route) {
            $guarded = in_array(SendContentSecurityPolicy::class, $route->gatherMiddleware(), true);

            self::assertSame($route->getName() !== PanelRoutes::ASSET, $guarded, "The panel route {$route->uri()} is ".($guarded ? '' : 'not ').'behind SendContentSecurityPolicy.');
        }
    }

    #[Test]
    public function it_fails_a_panel_page_with_the_way_to_build_the_panel_when_there_is_no_build(): void
    {
        $directory = sys_get_temp_dir().'/cms-panel-unbuilt-'.bin2hex(random_bytes(6));
        app()->forgetInstance(PanelBuild::class);
        app()->singleton(PanelBuild::class, static fn (): PanelBuild => ViteManifest::read($directory));

        $this->withoutExceptionHandling();
        $this->expectException(PanelBuildUnavailable::class);
        $this->expectExceptionMessage('Build it with `composer panel:build`.');

        $this->get('/cms/anything');
    }

    private function build(): PanelBuild
    {
        return $this->build ?? self::fail('The test has no build.');
    }

    /**
     * The Inertia page the root view rendered.
     *
     * @param  TestResponse<Response>  $response
     * @return array<array-key, mixed>
     */
    private function page(TestResponse $response): array
    {
        $page = $response->viewData('page');

        return is_array($page) ? $page : self::fail('The response rendered no Inertia page.');
    }

    /**
     * The nonce of the response's policy.
     *
     * @param  TestResponse<Response>  $response
     */
    private function nonce(TestResponse $response): string
    {
        $policy = (string) $response->headers->get(ContentSecurityPolicy::HEADER);

        return preg_match("/script-src 'nonce-([^']+)' 'strict-dynamic'/", $policy, $match) === 1
            ? $match[1]
            : self::fail("No script nonce in [{$policy}].");
    }
}
