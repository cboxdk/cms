<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Publishing\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementClosed;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementWindowSet;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\BecomesVisible;
use Cbox\Cms\Core\Placements\Domain\Dto\LocalePlacements;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use DateTimeImmutable;

/**
 * Which placements a plan makes visible, as the dry run of a command that publishes shows them
 * (PRD 6.4, 6.6): a placement in a locale is visible when the content has a released revision, or
 * needs none, and its own state and window make it visible (Visibility::visibleFrom()). The report
 * weighs every placement of the entry before and after the plan, at the time the command read, and
 * lists each one that is visible after it, now or later, and that was not visible before, or from
 * another time: the home placement a window puts live, and every placement whose window was open
 * already, which the release shows.
 */
#[Internal]
final readonly class VisibilityReport
{
    /**
     * @param  bool  $releasedBefore  whether the content had a released revision, or needs none, before the plan
     * @param  bool  $releasedAfter  whether it has one, or needs none, after the plan
     * @param  list<LocalePlacements>  $everywhere  every placement of the entry, one per locale
     * @return list<BecomesVisible>
     */
    public function of(bool $releasedBefore, bool $releasedAfter, array $everywhere, Plan $plan, DateTimeImmutable $at): array
    {
        $changes = [];

        foreach ($plan->mutations() as $mutation) {
            if ($mutation instanceof PlacementWindowSet) {
                $changes[$mutation->placement->toString()][$mutation->locale->value] = [Visibility::of($mutation->window, $at), $mutation->window];
            } elseif ($mutation instanceof PlacementClosed) {
                $changes[$mutation->placement->toString()][$mutation->locale->value] = [Visibility::Hidden, null];
            }
        }

        $visible = [];

        foreach ($everywhere as $placements) {
            foreach ($placements->states as $state) {
                $before = $releasedBefore ? $state->visibility->visibleFrom($state->window, $at) : null;
                [$visibility, $window] = $changes[$state->placement->toString()][$placements->locale->value] ?? [$state->visibility, $state->window];
                $after = $releasedAfter ? $visibility->visibleFrom($window, $at) : null;

                if ($after instanceof DateTimeImmutable && ! $this->same($before, $after)) {
                    $visible[] = new BecomesVisible($state->placement, $placements->locale, $after);
                }
            }
        }

        return $visible;
    }

    private function same(?DateTimeImmutable $before, DateTimeImmutable $after): bool
    {
        return $before instanceof DateTimeImmutable && $before->format('U.u') === $after->format('U.u');
    }
}
