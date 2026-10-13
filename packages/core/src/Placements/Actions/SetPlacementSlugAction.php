<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\RefusesCommand;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementSlugSet;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Placements\Domain\Commands\SetPlacementSlug;
use Cbox\Cms\Core\Placements\Domain\Dto\SetPlacementSlugAggregates;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredPlacement;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredPlacementLocale;
use Cbox\Cms\Core\Placements\Domain\PlacementReader;
use Cbox\Cms\Core\Placements\Domain\PlacementSlugRef;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Override;

/**
 * The write action of placement.set_slug (PRD 5.9, 6.4), exposed on every surface. resolve()
 * reads the placement, its version past the actor's regions, and whether the new slug is taken
 * below its node in the locale; refusals() refuses a placement the actor cannot reach, a locale the
 * placement does not have, a withdrawn placement, the slug the placement has already and a slug
 * another placement has; plan() is one PlacementSlugSet.
 *
 * It is decided on the placement's node (PRD 5.10): a placement below a node the actor's regions do
 * not reach reads as absent and the call is unauthorized. A withdrawn placement keeps its slug, as
 * it keeps everything else, until it is reinstated (invariant 7). The slug is an aggregate of its
 * own, read as free, so two commands that claim one slug commit one after the other (invariant 15).
 * A rename is not a visibility change: the plan's mutation makes nothing public, and the kernel
 * takes it from an agent too; the placement keeps its window, and a visible one stays visible at
 * its new URL while the old one answers 301 to it.
 *
 * @implements WriteAction<SetPlacementSlug, SetPlacementSlugAggregates>
 * @implements RefusesCommand<SetPlacementSlug, SetPlacementSlugAggregates>
 */
#[Action(handles: SetPlacementSlug::class, surfaces: [Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli])]
#[Internal]
final readonly class SetPlacementSlugAction implements RefusesCommand, WriteAction
{
    public function __construct(private PlacementReader $placements) {}

    /**
     * @param  SetPlacementSlug  $command
     */
    #[Override]
    public function resolve(Command $command): SetPlacementSlugAggregates
    {
        $stored = $this->placements->placement($command->placement);
        $slug = $stored instanceof StoredPlacement && ! $this->unchanged($command, $stored)
            ? new PlacementSlugRef($stored->node, $command->locale, $command->slug)
            : null;

        return new SetPlacementSlugAggregates(
            $command->placement,
            $this->placements->placementVersion($command->placement),
            $stored,
            $command->locale,
            $slug,
            $slug instanceof PlacementSlugRef && $this->placements->slugTaken($slug->node, $slug->locale, $slug->slug),
        );
    }

    /**
     * @param  SetPlacementSlug  $command
     * @param  SetPlacementSlugAggregates  $aggregates
     */
    #[Override]
    public function refusals(Command $command, Aggregates $aggregates): array
    {
        $stored = $aggregates->stored;

        if (! $stored instanceof StoredPlacement) {
            return [new CatalogError(ErrorCode::Unauthorized, new FieldPath('placement'), sprintf(
                'No placement %s is below a node the actor\'s grants reach, and a placement\'s slug is set with the rights on its node (PRD 5.10).',
                $command->placement->toString(),
            ))];
        }

        $locale = $stored->locale($command->locale);

        if (! $locale instanceof StoredPlacementLocale) {
            return [$this->invalid('locale', sprintf('The placement %s has no locale %s.', $command->placement->toString(), $command->locale->value))];
        }

        if ($locale->visibility === Visibility::Withdrawn) {
            return [$this->invalid('locale', sprintf(
                'The placement %s is withdrawn in %s, and only a reinstatement can change that (invariant 7).',
                $command->placement->toString(),
                $command->locale->value,
            ))];
        }

        if ($locale->slug->equals($command->slug)) {
            return [$this->invalid('slug', sprintf(
                'The placement %s has the slug "%s" in %s already.',
                $command->placement->toString(),
                $command->slug->value,
                $command->locale->value,
            ))];
        }

        if ($aggregates->taken) {
            return [new CatalogError(ErrorCode::PlacementSlugTaken, new FieldPath('slug'), sprintf(
                'Another placement below the node %s has the slug "%s" in %s.',
                $stored->node->toString(),
                $command->slug->value,
                $command->locale->value,
            ))];
        }

        return [];
    }

    /**
     * @param  SetPlacementSlug  $command
     * @param  SetPlacementSlugAggregates  $aggregates
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        return new Plan(new PlacementSlugSet($command->placement, $command->locale, $command->slug));
    }

    /**
     * Whether the placement has the slug in the locale already, so there is no slug to claim.
     */
    private function unchanged(SetPlacementSlug $command, StoredPlacement $stored): bool
    {
        $locale = $stored->locale($command->locale);

        return $locale instanceof StoredPlacementLocale && $locale->slug->equals($command->slug);
    }

    private function invalid(string $path, string $message): CatalogError
    {
        return new CatalogError(ErrorCode::ValidationFailed, new FieldPath($path), $message);
    }
}
