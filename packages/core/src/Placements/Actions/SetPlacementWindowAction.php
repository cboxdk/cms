<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\RefusesCommand;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Placements\Domain\Commands\SetPlacementWindow;
use Cbox\Cms\Core\Placements\Domain\Dto\EntryRelease;
use Cbox\Cms\Core\Placements\Domain\Dto\LocalePlacements;
use Cbox\Cms\Core\Placements\Domain\Dto\SetPlacementWindowAggregates;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredPlacement;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredPlacementLocale;
use Cbox\Cms\Core\Placements\Domain\PlacementReader;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use DateTimeImmutable;
use LogicException;
use Override;

/**
 * The write action of placement.set_window (PRD 5.7, 6.4), exposed on every surface. resolve()
 * reads the placement and every placement of its entry in the locale, at the Clock's time, and, for
 * a window that makes the placement live or scheduled, the entry's release and its type;
 * refusals() refuses a placement the actor cannot reach, a locale the placement does not have, a
 * withdrawn placement, and such a window for an entry that may not be shown; plan() is
 * PlacementPlanner::setWindow().
 *
 * It is decided on the placement's node (PRD 5.10): a placement below a node the actor's regions do
 * not reach reads as absent and the call is unauthorized. A withdrawn placement stays withdrawn
 * until it is reinstated (invariant 7). A placement is live, or scheduled to go live, only while its
 * entry is active and has a released revision, or is of a type with stages none (invariant 6), so a
 * window that would store it live or scheduled for another entry is validation_failed and points to
 * entry.publish, which releases and opens the window in one changeset; the entry's release is read
 * past the actor's regions, because the entry's home may lie elsewhere, and checked again at commit
 * (EntryReleaseRef). Hiding a placement, or a window that has ended, is always allowed. The kernel
 * refuses a window from an agent or a token (invariant 18), because PlacementWindowSet makes the
 * placement public.
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
        private TypeCatalog $types,
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
        $release = $stored instanceof StoredPlacement && $this->showsPlacement($command->window, $at)
            ? $this->placements->entryRelease($stored->entry)
            : null;

        return new SetPlacementWindowAggregates(
            $command->placement,
            $this->placements->placementVersion($command->placement),
            $stored,
            $command->locale,
            $stored instanceof StoredPlacement ? $this->placements->placements($stored->entry, $command->locale) : null,
            $at,
            $release,
            $release instanceof EntryRelease ? $this->types->find($release->type) : null,
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

        if ($this->showsPlacement($command->window, $aggregates->at) && ! $this->showable($aggregates)) {
            return [new CatalogError(ErrorCode::ValidationFailed, new FieldPath('window'), sprintf(
                'The entry %s is not active with a released revision, so a window cannot make its placement %s live or scheduled (invariant 6); release it and open the window together with entry.publish, or release it with variant.release first.',
                $stored->entry->toString(),
                $command->placement->toString(),
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

    /**
     * Whether the window stores the placement live or scheduled at the time; no window stores it
     * hidden, and a window that has ended stores it expired.
     */
    private function showsPlacement(?TimeWindow $window, DateTimeImmutable $at): bool
    {
        return $window instanceof TimeWindow && in_array(Visibility::of($window, $at), [Visibility::Live, Visibility::Scheduled], true);
    }

    private function showable(SetPlacementWindowAggregates $aggregates): bool
    {
        return $aggregates->release instanceof EntryRelease
            && $aggregates->type instanceof TypeDefinition
            && $aggregates->release->showable($aggregates->type->capabilities->stages);
    }
}
