<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Pipeline\Domain\PublicPlacements;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakePublicPlacements;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * PublicPlacementsBehaviour against the fake the pipeline's agent tests use.
 */
final class FakePublicPlacementsBehaviourTest extends TestCase
{
    use PublicPlacementsBehaviour;

    #[Override]
    protected function publicPlacements(): PublicPlacements
    {
        $shown = EntryId::fromString(self::SHOWN_ENTRY);
        $unshown = EntryId::fromString(self::UNSHOWN_ENTRY);

        return new FakePublicPlacements()
            ->with($shown, PlacementId::fromString(self::LIVE), new AggregateVersion(3), true)
            ->with($shown, PlacementId::fromString(self::WITHDRAWN), new AggregateVersion(1), false)
            ->with(EntryId::fromString(self::SCHEDULED_ENTRY), PlacementId::fromString(self::SCHEDULED), new AggregateVersion(2), true)
            ->with($unshown, PlacementId::fromString(self::HIDDEN), new AggregateVersion(2), false)
            ->with($unshown, PlacementId::fromString(self::EXPIRED), new AggregateVersion(1), false);
    }
}
