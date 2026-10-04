<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon\Tests\Unit;

use Cbox\Cms\Contracts\Addons\AddonManifest;
use Cbox\Cms\Testkit\Panel\PanelContributionsContract;
use Override;
use Workbench\FixtureAddon\FixtureAddonServiceProvider;
use Workbench\FixtureAddon\Tests\FixtureAddonTestCase;

/**
 * The fixture addon's panel contributions run the testkit's shared suite (PRD 13.4): cms:build
 * compiles the installation with the addon's manifest and refuses nothing, as every addon that
 * contributes to the panel checks in its own CI.
 */
final class FixtureAddonPanelContributionsTest extends FixtureAddonTestCase
{
    use PanelContributionsContract;

    #[Override]
    protected function addonManifest(): AddonManifest
    {
        return new FixtureAddonServiceProvider(app())->addonManifest();
    }
}
