<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Feature;

use Cbox\Cms\Panel\Boundary\BrandFileResponse;
use Cbox\Cms\Panel\Branding\Boundary\BrandingConfig;
use Cbox\Cms\Panel\Branding\Domain\Dto\Branding;
use Cbox\Cms\Panel\Domain\ContentSecurityPolicy;
use Cbox\Cms\Panel\Domain\Dto\PanelTheme;
use Cbox\Cms\Panel\Tests\Branding\BrandFixtures;
use Cbox\Cms\Panel\Tests\FixtureBuild;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Contracts\Config\Repository;
use Override;
use PHPUnit\Framework\Attributes\Test;

/**
 * The installation's brand and the theme's stylesheet as the panel serves them from its own
 * origin (PRD 13.4): every page shares the prop brand and links the favicon and, when a theme is
 * selected, its stylesheet with the response's nonce; a brand file and the stylesheet are served
 * by their hashed names, cached for good and never sniffed, and anything else is 404. A brand file
 * opened on its own is sandboxed and loads nothing.
 */
final class PanelBrandingTest extends TestCase
{
    private ?FixtureBuild $fixture = null;

    private ?BrandFixtures $files = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $app = $this->app ?? app();
        $this->fixture = FixtureBuild::write();
        $this->fixture->bind($app);
        $this->files = new BrandFixtures;
        $app->make(Repository::class)->set(BrandingConfig::KEY, [
            'root' => $this->files->root,
            'name' => 'Skovbo Content',
            'logo' => ['light' => 'brand/logo.svg', 'dark' => 'brand/logo-dark.svg', 'alt' => 'Skovbo'],
            'favicon' => 'brand/favicon.png',
        ]);
        $app->forgetInstance(Branding::class);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->fixture?->remove();
        $this->files?->remove();
        $this->fixture = null;
        $this->files = null;

        parent::tearDown();
    }

    #[Test]
    public function every_page_shares_the_brand_and_links_the_favicon_and_names_the_application(): void
    {
        $response = $this->get('/cms/login')->assertOk();
        $brand = app(Branding::class);
        $page = $response->viewData('page');
        $props = is_array($page) ? $page['props'] ?? [] : [];

        self::assertIsArray($props);
        self::assertEquals([
            'login' => ['alt' => 'Skovbo', 'dark' => '/cms/brand/'.$brand->logo?->dark->name, 'light' => '/cms/brand/'.$brand->logo?->light->name],
            'logo' => ['alt' => 'Skovbo', 'dark' => '/cms/brand/'.$brand->logo?->dark->name, 'light' => '/cms/brand/'.$brand->logo?->light->name],
            'name' => 'Skovbo Content',
        ], json_decode((string) json_encode($props['brand'] ?? null), true));

        $html = (string) $response->getContent();

        self::assertStringContainsString('<meta name="application-name" content="Skovbo Content">', $html);
        self::assertStringContainsString('<title inertia>Skovbo Content</title>', $html);
        self::assertStringContainsString('<link rel="icon" href="/cms/brand/'.$brand->favicon?->name.'" type="image/png">', $html);
        self::assertStringNotContainsString('/cms/theme/', $html);
    }

    #[Test]
    public function it_serves_a_brand_file_by_its_hashed_name_sandboxed_cached_for_good_and_not_sniffed(): void
    {
        $brand = app(Branding::class);
        $response = $this->get('/cms/brand/'.$brand->logo?->light->name);

        $response->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader(ContentSecurityPolicy::HEADER, BrandFileResponse::POLICY);

        self::assertSame(BrandFixtures::LIGHT, $response->getContent());
        self::assertTrue($response->headers->hasCacheControlDirective('immutable'));
        $this->get('/cms/brand/'.$brand->favicon?->name)->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    #[Test]
    public function it_answers_404_for_a_brand_file_it_does_not_hold(): void
    {
        $this->get('/cms/brand/logo-light-0000000000000000.svg')->assertNotFound();
        $this->get('/cms/brand/logo.svg')->assertNotFound();
        $this->get('/cms/brand/..%2f..%2fcomposer.json')->assertNotFound();
    }

    #[Test]
    public function it_links_and_serves_the_theme_stylesheet_by_its_version_when_a_theme_is_selected(): void
    {
        $css = "@layer cms.theme {\n:root {\n--cms-radius-md: 2px;\n}\n}\n";
        $theme = new PanelTheme($css);
        app()->instance(PanelTheme::class, $theme);

        $html = (string) $this->get('/cms/login')->assertOk()->getContent();

        self::assertMatchesRegularExpression('~<link rel="stylesheet" href="/cms/theme/'.$theme->version.'\.css" nonce="[A-Za-z0-9+/]{22}==">~', $html);

        $response = $this->get('/cms/theme/'.$theme->version.'.css');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/css; charset=utf-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        self::assertSame($css, $response->getContent());
        self::assertTrue($response->headers->hasCacheControlDirective('immutable'));
        $this->get('/cms/theme/0000000000000000.css')->assertNotFound();
    }

    #[Test]
    public function it_serves_no_theme_stylesheet_when_no_theme_is_selected(): void
    {
        app()->instance(PanelTheme::class, new PanelTheme(null));

        $this->get('/cms/theme/0000000000000000.css')->assertNotFound();
    }

    #[Test]
    public function without_branding_the_pages_name_cbox_cms_and_have_no_favicon(): void
    {
        app(Repository::class)->set(BrandingConfig::KEY);
        app()->forgetInstance(Branding::class);

        $html = (string) $this->get('/cms/login')->assertOk()->getContent();

        self::assertStringContainsString('<title inertia>Cbox CMS</title>', $html);
        self::assertStringContainsString('<link rel="icon" href="data:,">', $html);
        self::assertStringNotContainsString('application-name', $html);
    }
}
