<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Providers;

use Cbox\Cms\Contracts\Addons\AddonManifest;
use Cbox\Cms\Contracts\Build\DeclaresAddon;
use Cbox\Cms\Contracts\Build\DeclaresScanRoots;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\SlotFill;
use Cbox\Cms\Core\Tests\Registry\PanelBuildWorld;
use Cbox\Cms\Core\Tests\Registry\RegistryFixtures;
use Illuminate\Support\ServiceProvider;

/**
 * The fixture addon acme/cms-approvals as an application registers it: the scan roots of the host
 * and the addon fixtures, and a manifest whose two slot fills go to an experimental and a
 * deprecated point, with the bundle in Fixtures/PanelBundleValid.
 */
final class PanelAddonProvider extends ServiceProvider implements DeclaresAddon, DeclaresScanRoots
{
    public function scanRoots(): array
    {
        return [RegistryFixtures::root('PanelHost', PanelBuildWorld::HOST), RegistryFixtures::root('PanelAddon', PanelBuildWorld::ADDON)];
    }

    public function addonManifest(): AddonManifest
    {
        return PanelBuildWorld::manifest(
            [
                new SlotFill(new ContributionId('approvals.badge'), 'notes.detail.sections@1'),
                new SlotFill(new ContributionId('approvals.legacy'), 'notes.legacy@1'),
            ],
            bundle: __DIR__.'/../Fixtures/PanelBundleValid/dist',
        );
    }
}
