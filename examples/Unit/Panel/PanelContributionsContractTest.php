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
 * onto the section it fills. The suite's case fails with the build's output on any refusal. The
 * installation trusts the publisher's key for the addon's signed bundle, as every installation
 * outside the local environment must (cbox-cms.addons.publishers); the suite verifies the
 * signature as cms:build does.
 */
final class PanelContributionsContractTest extends BuildTestCase
{
    use PanelContributionsContract;

    /** The public key of the test key that signed the approvals bundle in dist/. */
    private const string PUBLISHER_KEY = 'KvpGeOheh2ZDEsrHAy/wRzWWnJdCf1AcwubMOddwn3Y=';

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        app()->register(ReviewsServiceProvider::class);
        app()->register(ApprovalsServiceProvider::class);
        $this->trustPublisher('acme/cms-approvals', self::PUBLISHER_KEY);
    }

    #[Override]
    protected function addonManifest(): AddonManifest
    {
        return new ApprovalsServiceProvider(app())->addonManifest();
    }
}
