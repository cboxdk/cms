<?php

declare(strict_types=1);

namespace Examples\Unit\Panel;

use Examples\Unit\Build\BuildTestCase;
use Examples\Unit\Panel\Approvals\ApprovalsServiceProvider;
use Examples\Unit\Panel\Reviews\ReviewsServiceProvider;
use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\Attributes\Test;

/**
 * cms:build compiles the approvals addon's panel contributions onto the review package's points:
 * the section goes to panel.php in render order, the addon and its checked bundle to addons.php,
 * and the build warns that the point is experimental. The installation can reorder the section
 * without touching the addon, refuses an addon its allowlist does not name, and refuses the bundle
 * unless its signature verifies with the publisher's key it trusts.
 */
final class PanelContributionsTest extends BuildTestCase
{
    /** The public key the approvals addon's publisher signed dist/panel-signature.json with. */
    private const string PUBLISHER_KEY = 'KvpGeOheh2ZDEsrHAy/wRzWWnJdCf1AcwubMOddwn3Y=';

    #[Test]
    public function it_compiles_the_addon_s_contribution_and_bundle(): void
    {
        $this->allowAddons('acme/cms-approvals');
        $this->trustPublisher('acme/cms-approvals', self::PUBLISHER_KEY);

        self::assertSame(0, $this->build(ReviewsServiceProvider::class, ApprovalsServiceProvider::class));
        self::assertStringContainsString('[registry_panel_point_experimental] The contribution approvals.badge of addon "approvals" (acme/cms-approvals) contributes to reviews.detail.sections@1', $this->buildOutput());

        $panel = require $this->registryFile('panel');
        self::assertIsArray($panel);
        self::assertIsArray($panel['entries']);
        $fills = array_column($panel['entries'], 'fills', 'id')['reviews.detail.sections@1'] ?? null;
        self::assertIsArray($fills);
        self::assertSame(['approvals.badge'], array_column($fills, 'contribution'));

        $addons = require $this->registryFile('addons');
        self::assertIsArray($addons);
        self::assertIsArray($addons['entries']);
        $approvals = array_column($addons['entries'], null, 'namespace')['approvals'] ?? null;
        self::assertIsArray($approvals);
        self::assertSame('internal', $approvals['reads']);
        self::assertIsArray($approvals['panel']);
        self::assertSame(['reviews.detail.sections@1'], $approvals['panel']['accepts_experimental']);
        self::assertIsArray($approvals['panel']['bundle']);
        self::assertSame('addon.js', $approvals['panel']['bundle']['entry']);
    }

    #[Test]
    public function it_lets_the_installation_reorder_a_contribution_and_shows_where_the_order_comes_from(): void
    {
        $this->allowAddons('acme/cms-approvals');
        $this->trustPublisher('acme/cms-approvals', self::PUBLISHER_KEY);
        config()->set('cbox-cms.panel.contributions', ['reviews.detail.sections@1' => ['approvals.badge' => ['priority' => 10]]]);

        self::assertSame(0, $this->build(ReviewsServiceProvider::class, ApprovalsServiceProvider::class));
        self::assertSame(0, app(Kernel::class)->call('cms:panel:fills', ['point' => 'reviews.detail.sections@1']));
        self::assertStringContainsString('priority 10 from the installation, enabled', app(Kernel::class)->output());
    }

    #[Test]
    public function it_refuses_the_bundle_when_the_installation_trusts_another_key_for_the_addon(): void
    {
        $this->allowAddons('acme/cms-approvals');
        $this->trustPublisher('acme/cms-approvals', 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');

        self::assertSame(65, $this->build(ReviewsServiceProvider::class, ApprovalsServiceProvider::class));

        $output = $this->buildOutput();
        self::assertStringContainsString('[registry_panel_bundle_unsigned] The panel bundle of addon "approvals" (acme/cms-approvals)', $output);
        self::assertStringContainsString('it is signed by the key '.self::PUBLISHER_KEY.', which the installation does not trust for the addon', $output);
    }

    #[Test]
    public function it_refuses_an_addon_the_allowlist_does_not_name_and_writes_nothing(): void
    {
        self::assertSame(65, $this->build(ReviewsServiceProvider::class, ApprovalsServiceProvider::class));
        self::assertStringContainsString('[registry_addon_not_allowed] Addon "approvals" (acme/cms-approvals) is installed', $this->buildOutput());
        self::assertDirectoryDoesNotExist($this->registryDirectory());
    }
}
