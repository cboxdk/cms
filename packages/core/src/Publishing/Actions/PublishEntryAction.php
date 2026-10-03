<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Publishing\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\RefusesCommand;
use Cbox\Cms\Contracts\Pipeline\ReportsVisibility;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\VariantReleased;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Entries\Actions\VariantReleasePlanner;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredEntry;
use Cbox\Cms\Core\Entries\Domain\EntryReader;
use Cbox\Cms\Core\Placements\Actions\PlacementPlanner;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredPlacement;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredPlacementLocale;
use Cbox\Cms\Core\Placements\Domain\PlacementReader;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Cbox\Cms\Core\Publishing\Domain\Commands\PublishEntry;
use Cbox\Cms\Core\Publishing\Domain\Dto\PublishEntryAggregates;
use Cbox\Cms\Core\Publishing\Domain\VisibilityReport;
use LogicException;
use Override;

/**
 * The write action of entry.publish (PRD 6.2, 6.4), exposed on every surface. It is composite: its
 * plan is the VariantReleasePlanner's release of the revision the command names followed by the
 * PlacementPlanner's window of the home placement in the locale, with the canonical moves that
 * window causes, so the release and the placement going live are authorized, validated and
 * committed as one changeset. It calls no other write action (GUARDRAILS 4.1).
 *
 * resolve() reads the entry with the head of its shared variant and its type, the home placement
 * and every placement of the entry in every locale, at the Clock's time. refusals() refuses a
 * placement the actor's regions do not reach (unauthorized), one that is not the entry's home
 * placement, a locale the placement does not have or is withdrawn in, and a revision a type with
 * stages none cannot have or a type with stages needs. plan() leaves out what changes nothing: the
 * release of the revision that is released already, and a window when the placement is live
 * already and the command asks for now, or has the window the command asks for. A type with stages
 * none is public as soon as it is saved, so its plan is the placement's alone. The kernel refuses
 * the release and the window to an agent (invariant 18). becomesVisible() gives the dry run every
 * placement the plan makes visible (VisibilityReport).
 *
 * @implements WriteAction<PublishEntry, PublishEntryAggregates>
 * @implements RefusesCommand<PublishEntry, PublishEntryAggregates>
 * @implements ReportsVisibility<PublishEntry, PublishEntryAggregates>
 */
#[Action(handles: PublishEntry::class, surfaces: [Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli])]
#[Internal]
final readonly class PublishEntryAction implements RefusesCommand, ReportsVisibility, WriteAction
{
    public function __construct(
        private EntryReader $entries,
        private PlacementReader $placements,
        private TypeCatalog $types,
        private Clock $clock,
        private VariantReleasePlanner $releases = new VariantReleasePlanner,
        private PlacementPlanner $planner = new PlacementPlanner,
        private VisibilityReport $report = new VisibilityReport,
    ) {}

    /**
     * @param  PublishEntry  $command
     */
    #[Override]
    public function resolve(Command $command): PublishEntryAggregates
    {
        $at = $this->clock->now();
        $stored = $this->entries->entry($command->entry, VariantKey::shared());

        return new PublishEntryAggregates(
            $command->entry,
            $stored,
            $stored instanceof StoredEntry ? $this->types->find($stored->type) : null,
            $command->placement,
            $this->placements->placementVersion($command->placement),
            $this->placements->placement($command->placement),
            $command->locale,
            $this->placements->everyLocale($command->entry),
            $at,
            $command->revision,
        );
    }

    /**
     * @param  PublishEntry  $command
     * @param  PublishEntryAggregates  $aggregates
     */
    #[Override]
    public function refusals(Command $command, Aggregates $aggregates): array
    {
        $placement = $aggregates->storedPlacement;

        if (! $placement instanceof StoredPlacement) {
            return [new CatalogError(ErrorCode::Unauthorized, new FieldPath('placement'), sprintf(
                'No placement %s is below a node the actor\'s grants reach, and a placement is put live with the rights on its node (PRD 5.10).',
                $command->placement->toString(),
            ))];
        }

        $entry = $aggregates->stored;
        $type = $aggregates->type;

        if (! $entry instanceof StoredEntry || ! $type instanceof TypeDefinition) {
            return [$this->invalid('entry', sprintf('The entry %s has no type of this installation.', $command->entry->toString()))];
        }

        if (! $placement->entry->equals($command->entry) || ! $placement->node->equals($entry->home)) {
            return [$this->invalid('placement', sprintf(
                'The placement %s is not a placement of the entry %s below its home node %s, which entry.publish puts live; use placement.set_window for another placement.',
                $command->placement->toString(),
                $command->entry->toString(),
                $entry->home->toString(),
            ))];
        }

        $errors = [];
        $locale = $placement->locale($command->locale);

        if (! $locale instanceof StoredPlacementLocale) {
            $errors[] = $this->invalid('locale', sprintf('The placement %s has no locale %s.', $command->placement->toString(), $command->locale->value));
        } elseif ($locale->visibility === Visibility::Withdrawn) {
            $errors[] = $this->invalid('locale', sprintf('The placement %s is withdrawn in %s, and only a reinstatement can change that (invariant 7).', $command->placement->toString(), $command->locale->value));
        }

        $staged = $type->capabilities->stages !== Stages::None;

        if ($staged && ! $command->revision instanceof RevisionNumber) {
            $errors[] = $this->invalid('revision', sprintf('The type %s has stages, so entry.publish names the revision to release.', $type->name->value));
        }

        if (! $staged && $command->revision instanceof RevisionNumber) {
            $errors[] = $this->invalid('revision', sprintf('The type %s has stages none: its entries are public as soon as they are saved, so there is no revision to release; leave the revision out.', $type->name->value));
        }

        return $errors;
    }

    /**
     * @param  PublishEntry  $command
     * @param  PublishEntryAggregates  $aggregates
     *
     * @throws LogicException when the entry, its type or the placement was read as absent, which the kernel rules out first
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        $entry = $aggregates->stored;
        $placement = $aggregates->storedPlacement?->locale($command->locale);

        if (! $entry instanceof StoredEntry || ! $aggregates->type instanceof TypeDefinition || ! $placement instanceof StoredPlacementLocale) {
            throw new LogicException(sprintf('entry.publish planned the entry %s, which it read without a type or a home placement in %s; the kernel refuses such a call first.', $command->entry->toString(), $command->locale->value));
        }

        $steps = [];

        if ($command->revision instanceof RevisionNumber && $aggregates->type->capabilities->stages !== Stages::None) {
            $steps[] = $this->releases->plan($entry, $command->revision);
        }

        if ($this->changesWindow($command, $placement, $aggregates)) {
            $steps[] = $this->planner->setWindow(
                $command->placement,
                $command->locale,
                $command->window ?? new TimeWindow($aggregates->at),
                $aggregates->inLocale(),
                $aggregates->at,
            );
        }

        return new Plan(...$steps);
    }

    /**
     * @param  PublishEntry  $command
     * @param  PublishEntryAggregates  $aggregates
     */
    #[Override]
    public function becomesVisible(Command $command, Aggregates $aggregates, Plan $plan): array
    {
        $unstaged = $aggregates->type?->capabilities->stages === Stages::None;
        $releasedBefore = $unstaged || $aggregates->stored?->head?->released instanceof RevisionNumber;
        $releases = array_filter($plan->mutations(), static fn (Mutation $mutation): bool => $mutation instanceof VariantReleased);

        return $this->report->of($releasedBefore, $releasedBefore || $releases !== [], $aggregates->everywhere, $plan, $aggregates->at);
    }

    /**
     * Whether the command changes the placement's window: a window it has not, or now for a
     * placement that is not live now.
     */
    private function changesWindow(PublishEntry $command, StoredPlacementLocale $placement, PublishEntryAggregates $aggregates): bool
    {
        if (! $command->window instanceof TimeWindow) {
            return ! $placement->visibility->visibleAt($placement->window, $aggregates->at);
        }

        return ! $placement->window instanceof TimeWindow
            || ! $placement->window->equals($command->window)
            || $placement->visibility !== Visibility::of($command->window, $aggregates->at);
    }

    private function invalid(string $path, string $message): CatalogError
    {
        return new CatalogError(ErrorCode::ValidationFailed, new FieldPath($path), $message);
    }
}
