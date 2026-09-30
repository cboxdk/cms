<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\RefusesCommand;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Placements\Domain\Commands\SetPlacementWindow;
use Cbox\Cms\Core\Placements\Domain\Dto\LocalePlacements;
use Cbox\Cms\Core\Placements\Domain\Dto\SetPlacementWindowAggregates;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredPlacement;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredPlacementLocale;
use Cbox\Cms\Core\Placements\Domain\PlacementReader;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use LogicException;
use Override;

/**
 * The write action of placement.set_window (PRD 5.7, 6.4), exposed on every surface. resolve()
 * reads the placement and every placement of its entry in the locale, at the Clock's time;
 * refusals() refuses a placement the actor cannot reach, a locale the placement does not have and
 * a withdrawn placement; plan() is PlacementPlanner::setWindow().
 *
 * It is decided on the placement's node (PRD 5.10): a placement below a node the actor's regions do
 * not reach reads as absent and the call is unauthorized. A withdrawn placement stays withdrawn
 * until it is reinstated (invariant 7). The kernel refuses a window from an agent or a token
 * (invariant 18), because PlacementWindowSet makes the placement public.
 *
 * @implements WriteAction<SetPlacementWindow, SetPlacementWindowAggregates>
 * @implements RefusesCommand<SetPlacementWindow, SetPlacementWindowAggregates>
 */
#[Action(handles: SetPlacementWindow::class, surfaces: [Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli])]
#[Internal]
final readonly class SetPlacementWindowAction implements RefusesCommand, WriteAction
{
    public function __construct(
        private PlacementReader $placements,
        private Clock $clock,
        private PlacementPlanner $planner = new PlacementPlanner,
    ) {}

    /**
     * @param  SetPlacementWindow  $command
     */
    #[Override]
    public function resolve(Command $command): SetPlacementWindowAggregates
    {
        $at = $this->clock->now();
        $stored = $this->placements->placement($command->placement);

        return new SetPlacementWindowAggregates(
            $command->placement,
            $this->placements->placementVersion($command->placement),
            $stored,
            $command->locale,
            $stored instanceof StoredPlacement ? $this->placements->placements($stored->entry, $command->locale) : null,
            $at,
        );
    }

    /**
     * @param  SetPlacementWindow  $command
     * @param  SetPlacementWindowAggregates  $aggregates
     */
    #[Override]
    public function refusals(Command $command, Aggregates $aggregates): array
    {
        $stored = $aggregates->stored;

        if (! $stored instanceof StoredPlacement) {
            return [new CatalogError(ErrorCode::Unauthorized, new FieldPath('placement'), sprintf(
                'No placement %s is below a node the actor\'s grants reach, and a placement\'s window is set with the rights on its node (PRD 5.10).',
                $command->placement->toString(),
            ))];
        }

        $locale = $stored->locale($command->locale);

        if (! $locale instanceof StoredPlacementLocale) {
            return [new CatalogError(ErrorCode::ValidationFailed, new FieldPath('locale'), sprintf(
                'The placement %s has no locale %s.',
                $command->placement->toString(),
                $command->locale->value,
            ))];
        }

        if ($locale->visibility === Visibility::Withdrawn) {
            return [new CatalogError(ErrorCode::ValidationFailed, new FieldPath('locale'), sprintf(
                'The placement %s is withdrawn in %s, and only a reinstatement can change that (invariant 7).',
                $command->placement->toString(),
                $command->locale->value,
            ))];
        }

        return [];
    }

    /**
     * @param  SetPlacementWindow  $command
     * @param  SetPlacementWindowAggregates  $aggregates
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        $placements = $aggregates->placements;

        if (! $placements instanceof LocalePlacements) {
            throw new LogicException(sprintf('placement.set_window planned the placement %s, which it read as absent; the kernel refuses such a call first.', $command->placement->toString()));
        }

        return $this->planner->setWindow($command->placement, $command->locale, $command->window, $placements, $aggregates->at);
    }
}
