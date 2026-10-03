<?php

declare(strict_types=1);

namespace Examples\Unit\Panel\Approvals;

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
 * The service provider of the addon acme/cms-approvals. Its manifest's panel member contributes a
 * section to the review page's slot reviews.detail.sections@1, which is experimental, so the
 * addon accepts it; the section's component is in the prebuilt bundle in dist.
 */
final class ApprovalsServiceProvider extends ServiceProvider implements DeclaresAddon
{
    public function addonManifest(): AddonManifest
    {
        return new AddonManifest(
            package: 'acme/cms-approvals',
            namespace: new AddonNamespace('approvals'),
            coreApi: new CoreApiVersion(1, 0),
            docs: __DIR__,
            capabilities: new AddonCapabilities(reads: ClassificationAccess::Internal),
            panel: new PanelContributions(
                sdk: new PanelApiVersion(1, 0),
                bundle: __DIR__.'/dist',
                acceptsExperimental: ['reviews.detail.sections@1'],
                contributions: [
                    new SlotFill(new ContributionId('approvals.badge'), 'reviews.detail.sections@1'),
                ],
            ),
        );
    }
}
