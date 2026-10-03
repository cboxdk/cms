<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Postgres;

use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Core\Registry\Adapter\ConfigPanelActivation;
use Cbox\Cms\Panel\Tests\Contributions\ContributionWorld;
use Cbox\Cms\Panel\Tests\Contributions\DeskWorld;
use Cbox\Cms\Panel\Tests\Contributions\VisitsDesk;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;

/**
 * The active contributions of a panel page per viewer (PRD 13.4), on real Postgres as the app
 * role over DeskWorld: the page lists, as cms.contributions, the contributions to the points it
 * renders that are enabled and in scope, and only those whose required permission the viewer holds
 * by its grants, as the PermissionRule decides; any other is never sent. The activation state
 * disables a contribution or an addon at the next request.
 */
final class ActiveContributionsTest extends TestCase
{
    use RealPostgres;
    use VisitsDesk;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->desk = new DeskWorld($this->app ?? self::fail('No application.'));
    }

    #[Test]
    public function it_shows_a_contribution_to_a_viewer_who_holds_its_permission_and_hides_it_from_one_who_does_not_never_listing_it(): void
    {
        $auditor = $this->visitDesk($this->desk()->auditor);
        $viewer = $this->visitDesk($this->desk()->viewer);

        self::assertSame(['desk.cards@1' => [ContributionWorld::AUDIT, ContributionWorld::COUNT, ContributionWorld::HEAVY]], self::listedFills($auditor));
        self::assertSame(['desk.cards@1' => [ContributionWorld::COUNT, ContributionWorld::HEAVY]], self::listedFills($viewer));
        self::assertStringNotContainsString(ContributionWorld::AUDIT.'"', (string) $viewer->getContent());
        self::assertSame([], $viewer->json('props.ext'));
        self::assertSame(['tally' => ['ext.tally']], $viewer->json('deferredProps'));
    }

    #[Test]
    public function it_sends_each_contribution_the_point_s_props_at_the_lower_of_the_viewer_s_access_and_the_addon_s_reads(): void
    {
        self::assertSame([
            ['action' => null, 'addon' => 'tally', 'check' => null, 'data' => false, 'decorator' => null, 'id' => ContributionWorld::AUDIT, 'kind' => 'slot', 'priority' => 10, 'props' => ['note' => DeskWorld::NOTE], 'replacement' => null, 'step' => null],
            ['action' => null, 'addon' => 'tally', 'check' => null, 'data' => true, 'decorator' => null, 'id' => ContributionWorld::COUNT, 'kind' => 'slot', 'priority' => 20, 'props' => ['note' => DeskWorld::NOTE], 'replacement' => null, 'step' => null],
            ['action' => null, 'addon' => 'tally', 'check' => null, 'data' => true, 'decorator' => null, 'id' => ContributionWorld::HEAVY, 'kind' => 'slot', 'priority' => 30, 'props' => ['note' => DeskWorld::NOTE], 'replacement' => null, 'step' => null],
        ], self::firstFills($this->visitDesk($this->desk()->auditor)));
    }

    #[Test]
    public function it_hands_an_addon_that_reads_confidential_the_memo_the_viewer_may_read(): void
    {
        $desk = new DeskWorld($this->app ?? self::fail('No application.'), ContributionWorld::registry(ContributionWorld::manifest(reads: ClassificationAccess::Confidential)), 62);
        $first = self::firstFills($this->visitDesk($desk->auditor))[0] ?? null;

        self::assertSame(['memo' => DeskWorld::MEMO, 'note' => DeskWorld::NOTE], is_array($first) ? $first['props'] ?? null : null);
    }

    #[Test]
    public function it_leaves_out_what_the_activation_state_disables_at_the_next_request_without_a_rebuild(): void
    {
        config([ConfigPanelActivation::KEY => ['contributions' => [ContributionWorld::AUDIT]]]);

        self::assertSame(['desk.cards@1' => [ContributionWorld::COUNT, ContributionWorld::HEAVY]], self::listedFills($this->visitDesk($this->desk()->auditor)));

        config([ConfigPanelActivation::KEY => ['addons' => ['tally']]]);
        $page = $this->visitDesk($this->desk()->auditor);

        self::assertSame([], self::listedFills($page));
        self::assertNull($page->json('props.ext'));
        self::assertNull($page->json('deferredProps'));
    }
}
