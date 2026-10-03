<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Contributions;

use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\PanelPoints\PageName;
use Cbox\Cms\Contracts\PanelPoints\PointName;
use Cbox\Cms\Core\Access\Domain\Dto\Grant;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeAccessContexts;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeHeldPermissions;
use Cbox\Cms\Core\Tests\Access\Fakes\FakePermissions;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakePanelActivation;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeRegistryCache;
use Cbox\Cms\Panel\Contributions\Actions\ResolveContributions;
use Cbox\Cms\Panel\Contributions\Domain\ContributionTelemetry;
use Cbox\Cms\Panel\Contributions\Domain\Dto\ActiveContributions;
use Cbox\Cms\Panel\Contributions\Domain\Dto\PanelView;
use Cbox\Cms\Panel\Contributions\Domain\Dto\RenderedPoint;
use Cbox\Cms\Panel\Contributions\Domain\Dto\ViewSubject;
use Cbox\Cms\Panel\Tests\Contributions\Fixtures\Desk\DeskAsideV1;
use Cbox\Cms\Panel\Tests\Contributions\Fixtures\Desk\DeskCardsV1;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;

/**
 * ResolveContributions over ContributionWorld's registry and fakes of its ports: AUDITOR holds a
 * role that may run tally.audit, with confidential access; VIEWER holds nothing, with confidential
 * access too.
 */
final readonly class ResolveWorld
{
    public const string AUDITOR = '0192a0c0-0000-7000-8000-000000000b01';

    public const string VIEWER = '0192a0c0-0000-7000-8000-000000000b02';

    public FakeRegistryCache $cache;

    public FakePanelActivation $activation;

    public FakeHeldPermissions $permissions;

    public FakeTelemetry $telemetry;

    public function __construct(?CompiledRegistry $registry = null)
    {
        $this->cache = ContributionWorld::cache($registry ?? ContributionWorld::registry());
        $this->activation = new FakePanelActivation;
        $this->telemetry = new FakeTelemetry;
        $this->permissions = new FakeHeldPermissions(
            new FakePermissions([])->grant(
                ActorId::fromString(self::AUDITOR),
                new Grant(RoleId::fromString('0192a0c0-0000-7000-8000-000000000b11'), ClassificationAccess::Confidential, new NodePath('a1'), GrantEffect::Allow),
                [new CommandName(ContributionWorld::AUDIT_PERMISSION)],
            ),
            new FakeAccessContexts()
                ->grant(ActorId::fromString(self::AUDITOR), ClassificationAccess::Confidential)
                ->grant(ActorId::fromString(self::VIEWER), ClassificationAccess::Confidential),
        );
    }

    public function action(): ResolveContributions
    {
        return new ResolveContributions($this->cache, $this->activation, $this->permissions, new ContributionTelemetry($this->telemetry));
    }

    public static function principal(string $actor): ActorPrincipal
    {
        return new ActorPrincipal(ActorId::fromString($actor), [], IssuerKind::Human, ClassificationAccess::Sensitive);
    }

    /**
     * The page as a viewer sees it, rendering desk.cards, and desk.aside when asked.
     */
    public function resolve(string $actor, bool $aside = false, ?object $cards = null, ViewSubject $subject = new ViewSubject): ActiveContributions
    {
        $points = [new RenderedPoint(new PointName('desk.cards'), $cards ?? new DeskCardsV1('Weekly desk', 'Call the printer'))];

        if ($aside) {
            $points[] = new RenderedPoint(new PointName('desk.aside'), new DeskAsideV1('Aside'));
        }

        return $this->action()->resolve(new PanelView(new PageName(ContributionWorld::PAGE), self::principal($actor), $points, $subject));
    }

    /**
     * Each active point with the ids of its fills in render order.
     *
     * @return array<string, list<string>>
     */
    public static function listed(ActiveContributions $active): array
    {
        $listed = [];

        foreach ($active->points as $point) {
            foreach ($point->fills as $fill) {
                $listed[$point->point->toString()][] = $fill->fill->contribution->value;
            }
        }

        return $listed;
    }
}
