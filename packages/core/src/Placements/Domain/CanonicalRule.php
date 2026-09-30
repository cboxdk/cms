<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Core\Placements\Domain\Dto\PlacementState;
use DateTimeImmutable;

/**
 * Which placement of an entry is canonical in a locale (PRD 5.7, invariant 14): at most one, and
 * exactly one while any placement is visible. The kernel applies it to the placements as a command
 * leaves them, at the time the command reads:
 *
 * 1. The canonical placement stays canonical while it is visible.
 * 2. Otherwise the first visible placement, by id, becomes canonical, so a visible placement holds
 *    the flag whenever one is visible.
 * 3. When none is visible, the canonical placement stays canonical unless it is withdrawn.
 * 4. When there is none, the first placement that is not withdrawn, by id, becomes canonical.
 *
 * A withdrawn placement is never canonical. Every placement that is not withdrawn can become
 * visible when time passes its window's start, with no command in between, and there is then
 * always a canonical placement already, so the rule holds between commands too; a placement that
 * becomes visible by time takes the flag from a hidden one at the next command that touches the
 * entry's placements in the locale, and with the planned transitions of block B3.
 */
#[Internal]
final readonly class CanonicalRule
{
    /**
     * @param  list<PlacementState>  $states
     */
    public function choose(array $states, DateTimeImmutable $at): ?PlacementId
    {
        $candidates = array_values(array_filter($states, static fn (PlacementState $state): bool => $state->candidate()));
        usort($candidates, static fn (PlacementState $one, PlacementState $other): int => strcmp($one->placement->toString(), $other->placement->toString()));

        $current = null;

        foreach ($candidates as $candidate) {
            if ($candidate->canonical) {
                $current = $candidate;
            }
        }

        if ($current instanceof PlacementState && $current->visibleAt($at)) {
            return $current->placement;
        }

        foreach ($candidates as $candidate) {
            if ($candidate->visibleAt($at)) {
                return $candidate->placement;
            }
        }

        if ($current instanceof PlacementState) {
            return $current->placement;
        }

        return $candidates === [] ? null : $candidates[0]->placement;
    }
}
