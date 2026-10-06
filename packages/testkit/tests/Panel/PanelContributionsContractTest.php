<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Panel;

use Cbox\Cms\Contracts\Addons\AddonManifest;
use Cbox\Cms\Testkit\Panel\PanelContributionsContract;
use Illuminate\Filesystem\Filesystem;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;
use Override;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use Workbench\FixtureAddon\FixtureAddonServiceProvider;

/**
 * The shared suite PanelContributionsContract (PRD 13.4): on the workbench's fixture addon, whose
 * manifest cms:build compiles, its case passes; and on an addon whose manifest the build refuses,
 * a slot fill of a point no package declares, the same case fails with the build's output, so an
 * addon's CI sees the refusal before an installation does. The application is the fixture
 * addon's own kind of Testbench application: discovery on, and a bootstrap directory of the
 * test's own, which cms:build writes into.
 */
final class PanelContributionsContractTest extends TestCase
{
    use PanelContributionsContract;
    use WithWorkbench;

    /** Discover the service providers of the installed packages, the fixture addon's among them. */
    #[Override]
    protected $enablesPackageDiscoveries = true;

    private string $bootstrap = '';

    private ?AddonManifest $manifest = null;

    #[Override]
    protected function defineEnvironment($app): void
    {
        $this->bootstrap = sys_get_temp_dir().'/cms-testkit-panel-'.bin2hex(random_bytes(8));
        mkdir($this->bootstrap, 0o700);

        $app->useBootstrapPath($this->bootstrap);
    }

    #[Override]
    protected function tearDown(): void
    {
        parent::tearDown();

        new Filesystem()->deleteDirectory($this->bootstrap);
    }

    #[Override]
    protected function addonManifest(): AddonManifest
    {
        return $this->manifest ?? new FixtureAddonServiceProvider(app())->addonManifest();
    }

    #[Test]
    public function it_fails_on_an_addon_whose_manifest_cms_build_refuses(): void
    {
        $provider = new BrokenAddonServiceProvider(app());
        $this->manifest = $provider->addonManifest();
        app()->register($provider);

        try {
            $this->its_panel_contributions_compile();
        } catch (AssertionFailedError $failed) {
            self::assertStringContainsString('cms:build refused the installation with the addon broken (acme/cms-broken), exit 65', $failed->getMessage());
            self::assertStringContainsString('[registry_panel_unknown_point]', $failed->getMessage());
            self::assertStringContainsString(BrokenAddonServiceProvider::POINT, $failed->getMessage());

            return;
        }

        self::fail('The case passed on a manifest cms:build refuses.');
    }
}
