<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Feature;

use Cbox\Cms\Panel\Boundary\ViteManifest;
use Cbox\Cms\Panel\Domain\ContentSecurityPolicy;
use Cbox\Cms\Panel\Domain\CspNonce;
use Cbox\Cms\Panel\Domain\Dto\PagePolicy;
use Cbox\Cms\Panel\Domain\Dto\PanelBuild;
use Cbox\Cms\Panel\Domain\PanelBuildUnavailable;
use Cbox\Cms\Panel\Domain\PanelRoute;
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
 * at /cms: every page carries a strict Content-Security-Policy with a nonce of its own for its
 * styles and the hash of its import map for its scripts, the root view puts that nonce on the
 * build's script, stylesheets and module preloads and on the csp-nonce meta element, and a path
 * the panel does not have is the page Errors/NotFound with 404.
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
    public function it_sends_a_strict_policy_whose_nonce_is_on_the_import_map_the_script_the_stylesheets_the_preloads_and_the_meta_element_and_on_nothing_else(): void
    {
        $response = $this->get('/cms/anywhere');
        $nonce = $this->nonce($response);
        $html = (string) $response->getContent();

        self::assertSame(1, preg_match('~<script type="importmap" nonce="[^"]+">(.*?)</script>~s', $html, $map));
        self::assertSame(
            ContentSecurityPolicy::header(new PagePolicy(new CspNonce($nonce), [PagePolicy::hashOf($map[1] ?? '')], '/cms/csp-report')),
            $response->headers->get(ContentSecurityPolicy::HEADER),
        );
        self::assertSame('cms-csp="/cms/csp-report"', $response->headers->get(ContentSecurityPolicy::REPORTING_ENDPOINTS));
        self::assertStringNotContainsString("'nonce-{$nonce}' 'strict-dynamic'", $response->headers->get(ContentSecurityPolicy::HEADER));
        self::assertStringContainsString('<meta property="csp-nonce" nonce="'.$nonce.'">', $html);
        self::assertStringContainsString('<script type="module" src="/cms/build/assets/app-1a2b3c.js" nonce="'.$nonce.'"></script>', $html);
        self::assertStringContainsString('<link rel="stylesheet" href="/cms/build/assets/shared-3c4d5e.css" nonce="'.$nonce.'">', $html);
        self::assertStringContainsString('<link rel="stylesheet" href="/cms/build/assets/app-7a8b9c.css" nonce="'.$nonce.'">', $html);
        self::assertStringContainsString('<link rel="modulepreload" href="/cms/build/assets/shared-4d5e6f.js" nonce="'.$nonce.'">', $html);
        self::assertStringContainsString('<script type="importmap" nonce="'.$nonce.'">', $html);
        self::assertSame(6, substr_count($html, 'nonce="'.$nonce.'"'));
        self::assertSame(0, preg_match_all('/<script(?![^>]*type="(?:module|application\/json|importmap)")/', $html));
    }

    #[Test]
    public function it_writes_the_import_map_with_the_shared_modules_and_the_integrity_of_every_script_before_any_module_is_loaded(): void
    {
        $html = (string) $this->get('/cms/anywhere')->getContent();
        $integrity = 'sha384-'.base64_encode(hash('sha384', FixtureBuild::SCRIPT, true));

        self::assertSame(1, preg_match('~<script type="importmap" nonce="[^"]+">(.*?)</script>~s', $html, $match));
        self::assertLessThan(strpos($html, '<link rel="modulepreload"'), strpos($html, '<script type="importmap"'));
        self::assertLessThan(strpos($html, '<script type="module"'), strpos($html, '<script type="importmap"'));

        $map = json_decode($match[1] ?? '', true, 8, JSON_THROW_ON_ERROR);

        self::assertSame([
            'imports' => [
                '@cboxdk/cms-panel/experimental' => '/cms/build/assets/shared-cboxdk-cms-panel-experimental-1a1a1a.js',
                '@cboxdk/cms-panel/extend' => '/cms/build/assets/shared-cboxdk-cms-panel-extend-1b1b1b.js',
                '@cboxdk/cms-panel/ui' => '/cms/build/assets/shared-cboxdk-cms-panel-ui-1c1c1c.js',
                'react' => '/cms/build/assets/shared-react-0a0a0a.js',
                'react-dom' => '/cms/build/assets/shared-react-dom-0c0c0c.js',
                'react-dom/client' => '/cms/build/assets/shared-react-dom-client-0d0d0d.js',
                'react/jsx-runtime' => '/cms/build/assets/shared-react-jsx-runtime-0b0b0b.js',
            ],
            'scopes' => [],
            'integrity' => array_fill_keys(array_map(
                static fn (string $file): string => '/cms/build/'.$file,
                array_values(array_filter($this->build()->files, static fn (string $file): bool => str_ends_with($file, '.js'))),
            ), $integrity),
        ], $map);
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
        self::assertStringContainsString("script-src 'self';", (string) $response->headers->get(ContentSecurityPolicy::HEADER));
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
    public function it_runs_every_panel_page_behind_the_content_security_policy_and_only_the_files_it_serves_and_the_report_without_it(): void
    {
        $panelRoutes = array_values(array_filter(
            app(Router::class)->getRoutes()->getRoutes(),
            static fn (Route $route): bool => str_starts_with($route->uri(), 'cms/') || $route->uri() === 'cms',
        ));
        $names = array_map(static fn (Route $route): ?string => $route->getName(), $panelRoutes);

        // The files the panel serves, not pages: the build's, the theme's stylesheet, the brand's
        // images, which BrandFileResponse gives a sandboxing policy of its own, and the addons'
        // files; and the route a browser reports a violation to, which renders nothing.
        $files = [PanelRoutes::ASSET, PanelRoute::Theme->value, PanelRoute::Brand->value, PanelRoute::AddonAsset->value, PanelRoute::CspReport->value];

        self::assertContains(PanelRoutes::ASSET, $names);
        self::assertContains(PanelRoute::Theme->value, $names);
        self::assertContains(PanelRoute::Brand->value, $names);
        self::assertContains(PanelRoute::AddonAsset->value, $names);
        self::assertContains(PanelRoute::CspReport->value, $names);
        self::assertContains(PanelRoutes::NOT_FOUND, $names);

        foreach ($panelRoutes as $route) {
            $guarded = in_array(SendContentSecurityPolicy::class, $route->gatherMiddleware(), true);

            self::assertSame(! in_array($route->getName(), $files, true), $guarded, "The panel route {$route->uri()} is ".($guarded ? '' : 'not ').'behind SendContentSecurityPolicy.');
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
     * The nonce of the response's policy, which its styles carry.
     *
     * @param  TestResponse<Response>  $response
     */
    private function nonce(TestResponse $response): string
    {
        $policy = (string) $response->headers->get(ContentSecurityPolicy::HEADER);

        return preg_match("/style-src 'self' 'nonce-([^']+)'/", $policy, $match) === 1
            ? $match[1]
            : self::fail("No style nonce in [{$policy}].");
    }
}
