<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Panel;

use Cbox\Cms\Contracts\Addons\AddonCapabilities;
use Cbox\Cms\Contracts\Addons\AddonManifest;
use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Addons\CoreApiVersion;
use Cbox\Cms\Contracts\Build\DeclaresAddon;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\PanelApiVersion;
use Cbox\Cms\Contracts\PanelPoints\PanelContributions;
use Cbox\Cms\Contracts\PanelPoints\SlotFill;
use Illuminate\Support\ServiceProvider;

/**
 * An addon whose manifest cms:build refuses: its one contribution fills a point no package
 * declares, which the build reports as registry_panel_unknown_point.
 */
final class BrokenAddonServiceProvider extends ServiceProvider implements DeclaresAddon
{
    public const string PACKAGE = 'acme/cms-broken';

    public const string NAMESPACE = 'broken';

    public const string CONTRIBUTION = 'broken.card';

    public const string POINT = 'nowhere.cards@1';

    public function addonManifest(): AddonManifest
    {
        return new AddonManifest(
            package: self::PACKAGE,
            namespace: new AddonNamespace(self::NAMESPACE),
            coreApi: new CoreApiVersion(CoreApiVersion::CURRENT_MAJOR, CoreApiVersion::CURRENT_MINOR),
            docs: __DIR__,
            capabilities: new AddonCapabilities(ClassificationAccess::Public),
            panel: new PanelContributions(
                sdk: PanelApiVersion::current(),
                acceptsExperimental: [self::POINT],
                contributions: [new SlotFill(new ContributionId(self::CONTRIBUTION), self::POINT)],
            ),
        );
    }
}
