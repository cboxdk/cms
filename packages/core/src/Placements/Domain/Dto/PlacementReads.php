<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;

/**
 * The aggregate reads the placement commands share: the canonical slot of an entry in a locale and
 * the version of each of its placements, and a list of reads with each aggregate once, the first
 * read of it kept, because a command reads its own placement both on its own and among the
 * entry's.
 */
#[Internal]
final readonly class PlacementReads
{
    /**
     * @return list<ReadVersion>
     */
    public static function of(LocalePlacements $placements): array
    {
        $reads = [$placements->canonical() instanceof PlacementState
            ? ReadVersion::at($placements->slot(), new AggregateVersion(1))
            : ReadVersion::absent($placements->slot())];

        foreach ($placements->states as $state) {
            $reads[] = ReadVersion::at($state->placement, $state->version);
        }

        return $reads;
    }

    /**
     * @param  list<ReadVersion>  $reads
     */
    public static function unique(array $reads): ReadVersions
    {
        $byKey = [];

        foreach ($reads as $read) {
            $byKey[$read->aggregate->aggregateKey()] ??= $read;
        }

        return new ReadVersions(...array_values($byKey));
    }
}
