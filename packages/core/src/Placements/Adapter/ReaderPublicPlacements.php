<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Core\Pipeline\Domain\Dto\EntryPlacements;
use Cbox\Cms\Core\Pipeline\Domain\PublicPlacements;
use Cbox\Cms\Core\Placements\Domain\PlacementReader;
use DateTimeImmutable;
use Override;

/**
 * The placements of an entry through the PlacementReader's everyLocale(), past the actor's regions,
 * one statement however many placements the entry has: each placement once at its version, and the
 * first, by locale and then id, that Visibility::visibleFrom() shows now or later at the Clock's time.
 */
#[Internal]
final readonly class ReaderPublicPlacements implements PublicPlacements
{
    public function __construct(
        private PlacementReader $placements,
        private Clock $clock,
    ) {}

    #[Override]
    public function of(EntryId $entry): EntryPlacements
    {
        $now = $this->clock->now();
        $reads = [];
        $shown = null;

        foreach ($this->placements->everyLocale($entry) as $locale) {
            foreach ($locale->states as $state) {
                $reads[$state->placement->toString()] = ReadVersion::at($state->placement, $state->version);

                if (! $shown instanceof PlacementId && $state->visibility->visibleFrom($state->window, $now) instanceof DateTimeImmutable) {
                    $shown = $state->placement;
                }
            }
        }

        ksort($reads);

        return new EntryPlacements(new ReadVersions(...array_values($reads)), $shown);
    }
}
