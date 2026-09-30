<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Core\Placements\Domain\CanonicalPlacementRef;

/**
 * Every placement of an entry in one locale, on every site, in the order of their ids (PRD 5.7):
 * what the kernel reads to keep one canonical placement per entry and locale (invariant 14).
 */
#[Internal]
final readonly class LocalePlacements
{
    /**
     * @param  list<PlacementState>  $states
     */
    public function __construct(
        public EntryId $entry,
        public Locale $locale,
        public array $states,
    ) {}

    /**
     * The placement that is canonical now, or null when none is.
     */
    public function canonical(): ?PlacementState
    {
        foreach ($this->states as $state) {
            if ($state->canonical) {
                return $state;
            }
        }

        return null;
    }

    public function of(PlacementId $placement): ?PlacementState
    {
        foreach ($this->states as $state) {
            if ($state->placement->equals($placement)) {
                return $state;
            }
        }

        return null;
    }

    /**
     * The aggregate that says whether the entry has a canonical placement in the locale.
     */
    public function slot(): CanonicalPlacementRef
    {
        return new CanonicalPlacementRef($this->entry, $this->locale);
    }
}
