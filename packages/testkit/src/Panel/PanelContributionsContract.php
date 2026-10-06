<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Panel;

use Cbox\Cms\Contracts\Addons\AddonManifest;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PanelContribution;
use Cbox\Cms\Testkit\Panel\Boundary\CompiledPanelFiles;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * The shared suite for an addon's panel contributions (PRD 13.4, section 7 of the panel extension
 * architecture): what every addon that contributes to the panel runs in its own CI, so a manifest
 * cms:build would refuse fails the addon's tests before an installation sees it.
 *
 * Use the trait in a PHPUnit test class on the addon's Testbench application, with package
 * discovery on, so the addon's provider is registered, and a bootstrap directory of the test's
 * own, so cms:build writes its cache there, and give the manifest the provider declares:
 *
 *     final class PanelContributionsTest extends TestCase
 *     {
 *         use PanelContributionsContract;
 *
 *         protected function addonManifest(): AddonManifest
 *         {
 *             return new ApprovalsServiceProvider(app())->addonManifest();
 *         }
 *     }
 *
 * The case allows the addon's package in cbox-cms.addons.allowed, runs cms:build on the whole
 * installation and fails with the build's output on any problem: an unknown point, a kind
 * mismatch, an experimental point the manifest does not accept, a bundle whose files or ids are
 * not the manifest's, a blocking check without a mirrored hook and every other refusal of
 * PanelCompiler. It then reads what the build wrote and asserts that the addon was compiled and
 * that each contribution of the manifest is on its point, so a contribution the build left out
 * fails too.
 */
#[Experimental]
trait PanelContributionsContract
{
    /**
     * The manifest of the addon under test, as its service provider declares it.
     */
    abstract protected function addonManifest(): AddonManifest;

    #[Test]
    public function its_panel_contributions_compile(): void
    {
        $manifest = $this->addonManifest();
        $app = $this->panelApplication();
        $config = $app->make(Repository::class);
        $allowed = $config->get('cbox-cms.addons.allowed');

        if (is_array($allowed) && ! in_array($manifest->package, $allowed, true)) {
            $config->set('cbox-cms.addons.allowed', [...array_values($allowed), $manifest->package]);
        }

        $kernel = $app->make(Kernel::class);
        $status = $kernel->call('cms:build');

        Assert::assertSame(0, $status, sprintf(
            "cms:build refused the installation with the addon %s (%s), exit %d:\n%s",
            $manifest->namespace->value,
            $manifest->package,
            $status,
            $kernel->output(),
        ));

        $compiled = CompiledPanelFiles::read($app->bootstrapPath('cache/cms'));

        Assert::assertContains($manifest->namespace->value, $compiled->addons, sprintf(
            'cms:build compiled no addon with the namespace %s; it compiled %s. Is the provider registered, and does it implement DeclaresAddon?',
            $manifest->namespace->value,
            $compiled->addons === [] ? 'none' : implode(', ', $compiled->addons),
        ));

        foreach ($manifest->panel->contributions ?? [] as $contribution) {
            $this->assertContributionCompiled($compiled, $contribution);
        }
    }

    private function assertContributionCompiled(CompiledPanelFiles $compiled, PanelContribution $contribution): void
    {
        $ids = $compiled->contributionsOf($contribution->point());

        Assert::assertContains($contribution->id()->value, $ids, sprintf(
            'cms:build compiled the point %s without the contribution %s; it has %s.',
            $contribution->point(),
            $contribution->id()->value,
            $ids === [] ? 'no contribution' : implode(', ', $ids),
        ));
    }

    /**
     * The application the test booted.
     *
     * @throws RuntimeException when no application is booted
     */
    private function panelApplication(): Application
    {
        $app = Container::getInstance();

        if (! $app instanceof Application) {
            throw new RuntimeException('PanelContributionsContract runs in a booted application: use it in a test class on Testbench.');
        }

        return $app;
    }
}
