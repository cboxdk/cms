<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Fakes;

use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Core\Pipeline\Domain\Dto\EntryPlacements;
use Cbox\Cms\Core\Pipeline\Domain\PublicPlacements;
use Override;

/**
 * The placements a test puts on it, in memory: each placement of an entry at its version and
 * whether it is visible now or later, read as ReaderPublicPlacements reads them, every placement
 * once in the order of its id, and the first shown one, in the order the test put them, as the one
 * that shows the entry. An entry the test put nothing on has no placement.
 * PublicPlacementsBehaviour holds it to ReaderPublicPlacements.
 */
final class FakePublicPlacements implements PublicPlacements
{
    /** @var array<string, list<array{PlacementId, AggregateVersion, bool}>> by entry */
    private array $placements = [];

    public function with(EntryId $entry, PlacementId $placement, AggregateVersion $version, bool $shown): self
    {
        $this->placements[$entry->toString()][] = [$placement, $version, $shown];

        return $this;
    }

    #[Override]
    public function of(EntryId $entry): EntryPlacements
    {
        $reads = [];
        $shown = null;

        foreach ($this->placements[$entry->toString()] ?? [] as [$placement, $version, $visible]) {
            $reads[$placement->toString()] = ReadVersion::at($placement, $version);

            if ($visible && ! $shown instanceof PlacementId) {
                $shown = $placement;
            }
        }

        ksort($reads);

        return new EntryPlacements(new ReadVersions(...array_values($reads)), $shown);
    }
}
