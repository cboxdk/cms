<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementCanonicalSet;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementCreated;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementLocaleAdded;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementWindowSet;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Core\Placements\Domain\CanonicalRule;
use Cbox\Cms\Core\Placements\Domain\Commands\CreatePlacement;
use Cbox\Cms\Core\Placements\Domain\Dto\CreatePlacementAggregates;
use Cbox\Cms\Core\Placements\Domain\Dto\LocalePlacements;
use Cbox\Cms\Core\Placements\Domain\Dto\PlacementState;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use DateTimeImmutable;
use LogicException;

/**
 * The kernel's planner of placements (PRD 5.7, 6.2 phase 3): the plans of placement.create and
 * placement.set_window, which other commands compose, such as entry.publish with the home
 * placement going live. It computes a plan and nothing else.
 *
 * Every plan keeps one canonical placement of the entry in each locale it touches (invariant 14):
 * it applies the CanonicalRule to the entry's placements as the plan leaves them, at the time the
 * command read, and moves the flag when the rule says so, clearing the old placement before it
 * sets the new one, so the database's "at most one" holds after every statement.
 */
#[Internal]
final readonly class PlacementPlanner
{
    public function __construct(private CanonicalRule $canonical = new CanonicalRule) {}

    /**
     * The plan that creates the placement with its slugs, hidden in every locale.
     */
    public function create(CreatePlacement $command, CreatePlacementAggregates $aggregates): Plan
    {
        $steps = [new PlacementCreated($command->placement, $command->entry, $command->node, $command->site)];

        foreach ($command->slugs as $slug) {
            $placements = $aggregates->placementsIn($slug->locale)
                ?? throw new LogicException(sprintf('placement.create read no placements of the entry in %s.', $slug->locale->value));
            $current = $placements->canonical()?->placement;
            $new = new PlacementState($command->placement, AggregateVersion::first(), Visibility::Hidden, null, false);
            $chosen = $this->canonical->choose([...$placements->states, $new], $aggregates->at);

            if ($current instanceof PlacementId && ! $this->same($current, $chosen)) {
                $steps[] = new PlacementCanonicalSet($current, $slug->locale, false);
            }

            $steps[] = new PlacementLocaleAdded($command->placement, $slug->locale, $slug->slug, $this->same($command->placement, $chosen));

            if ($chosen instanceof PlacementId && ! $this->same($chosen, $command->placement) && ! $this->same($chosen, $current)) {
                $steps[] = new PlacementCanonicalSet($chosen, $slug->locale, true);
            }
        }

        return new Plan(...$steps);
    }

    /**
     * The plan that sets the placement's window in the locale, or hides it there for a null
     * window, with the move of the canonical flag it causes.
     */
    public function setWindow(PlacementId $placement, Locale $locale, ?TimeWindow $window, LocalePlacements $placements, DateTimeImmutable $at): Plan
    {
        $visibility = Visibility::of($window, $at);
        $states = [];
        $found = false;

        foreach ($placements->states as $state) {
            if ($state->placement->equals($placement)) {
                $state = $state->withWindow($visibility, $window);
                $found = true;
            }

            $states[] = $state;
        }

        if (! $found) {
            $states[] = new PlacementState($placement, AggregateVersion::first(), $visibility, $window, false);
        }

        $current = $placements->canonical()?->placement;
        $chosen = $this->canonical->choose($states, $at);

        return new Plan(new PlacementWindowSet($placement, $locale, $window), ...$this->moves($locale, $current, $chosen));
    }

    /**
     * @return list<Mutation>
     */
    private function moves(Locale $locale, ?PlacementId $current, ?PlacementId $chosen): array
    {
        if ($this->same($current, $chosen)) {
            return [];
        }

        $moves = [];

        if ($current instanceof PlacementId) {
            $moves[] = new PlacementCanonicalSet($current, $locale, false);
        }

        if ($chosen instanceof PlacementId) {
            $moves[] = new PlacementCanonicalSet($chosen, $locale, true);
        }

        return $moves;
    }

    private function same(?PlacementId $one, ?PlacementId $other): bool
    {
        return $one instanceof PlacementId && $other instanceof PlacementId ? $one->equals($other) : $one === $other;
    }
}
