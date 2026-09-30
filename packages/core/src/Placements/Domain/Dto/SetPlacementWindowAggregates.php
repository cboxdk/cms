<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use DateTimeImmutable;
use Override;

/**
 * What placement.set_window read (PRD 6.2 phase 1), at the time it read: the placement's version,
 * null when it does not exist; the placement itself, null also when the actor's regions do not
 * reach its node; and every placement of its entry in the locale, for the canonical rule, null with
 * it. The kernel checks at commit that the
 * placement and every other placement of the entry in the locale are still at the versions read.
 */
#[Internal]
final readonly class SetPlacementWindowAggregates implements Aggregates
{
    public function __construct(
        public PlacementId $placement,
        public ?AggregateVersion $version,
        public ?StoredPlacement $stored,
        public Locale $locale,
        public ?LocalePlacements $placements,
        public DateTimeImmutable $at,
    ) {}

    #[Override]
    public function versions(): ReadVersions
    {
        $reads = [new ReadVersion($this->placement, $this->version)];

        if ($this->placements instanceof LocalePlacements) {
            array_push($reads, ...PlacementReads::of($this->placements));
        }

        return PlacementReads::unique($reads);
    }
}
