<?php

declare(strict_types=1);

namespace Examples\Unit\Panel;

use Cbox\Cms\Contracts\Addons\AddonManifest;
use Cbox\Cms\Testkit\Panel\PanelContributionsContract;
use Examples\Unit\Build\BuildTestCase;
use Examples\Unit\Panel\Approvals\ApprovalsServiceProvider;
use Examples\Unit\Panel\Reviews\ReviewsServiceProvider;
use Override;

/**
 * The approvals addon runs the testkit's shared suite in its own tests: the installation with
 * the review package's points and the addon's manifest builds, and the addon's badge is compiled
 * onto the section it fills. The suite's case fails with the build's output on any refusal.
 */
final class PanelContributionsContractTest extends BuildTestCase
{
    use PanelContributionsContract;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        app()->register(ReviewsServiceProvider::class);
        app()->register(ApprovalsServiceProvider::class);
    }

    #[Override]
    protected function addonManifest(): AddonManifest
    {
        return new ApprovalsServiceProvider(app())->addonManifest();
    }
}
