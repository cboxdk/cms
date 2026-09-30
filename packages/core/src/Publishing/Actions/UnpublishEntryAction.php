<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Publishing\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Core\Entries\Actions\VariantReleasePlanner;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredEntry;
use Cbox\Cms\Core\Entries\Domain\EntryReader;
use Cbox\Cms\Core\Placements\Actions\PlacementPlanner;
use Cbox\Cms\Core\Placements\Domain\PlacementReader;
use Cbox\Cms\Core\Publishing\Domain\Commands\UnpublishEntry;
use Cbox\Cms\Core\Publishing\Domain\Dto\UnpublishEntryAggregates;
use LogicException;
use Override;

/**
 * The write action of entry.unpublish (PRD 6.2, 6.4), exposed on every surface, the reverse of
 * entry.publish. It is composite: its plan is the VariantReleasePlanner's unrelease of the shared
 * variant followed by the PlacementPlanner's close of the entry's placements in each locale, so the
 * content goes back to unreleased and every placement that is visible now or later is hidden in one
 * changeset (PRD 6.4). It calls no other write action (GUARDRAILS 4.1).
 *
 * resolve() reads the entry with the head of its shared variant and every placement of the entry in
 * every locale, on every site, at the Clock's time. Unpublishing is decided on the entry's home
 * (PRD 5.10): an entry the actor cannot reach reads as absent, and the command, which expects its
 * variant at a version, is version_conflict; the placements are closed wherever they are. A type
 * with stages none has no release to take back, so its plan only closes placements, and a call
 * that has nothing to take back or close changes nothing and is rejected as such.
 *
 * @implements WriteAction<UnpublishEntry, UnpublishEntryAggregates>
 */
#[Action(handles: UnpublishEntry::class, surfaces: [Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli])]
#[Internal]
final readonly class UnpublishEntryAction implements WriteAction
{
    public function __construct(
        private EntryReader $entries,
        private PlacementReader $placements,
        private Clock $clock,
        private VariantReleasePlanner $releases = new VariantReleasePlanner,
        private PlacementPlanner $planner = new PlacementPlanner,
    ) {}

    /**
     * @param  UnpublishEntry  $command
     */
    #[Override]
    public function resolve(Command $command): UnpublishEntryAggregates
    {
        $at = $this->clock->now();

        return new UnpublishEntryAggregates(
            $command->entry,
            $this->entries->entry($command->entry, VariantKey::shared()),
            $this->placements->everyLocale($command->entry),
            $at,
        );
    }

    /**
     * @param  UnpublishEntry  $command
     * @param  UnpublishEntryAggregates  $aggregates
     *
     * @throws LogicException when the entry was read as absent, which the kernel's check of the expected version rules out
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        if (! $aggregates->stored instanceof StoredEntry) {
            throw new LogicException(sprintf(
                'entry.unpublish planned the entry %s, which was read as absent; the kernel rejects such a call with version_conflict before it plans.',
                $command->entry->toString(),
            ));
        }

        $steps = [$this->releases->unrelease($aggregates->stored)];

        foreach ($aggregates->everywhere as $placements) {
            $steps[] = $this->planner->close($placements, $aggregates->at);
        }

        return new Plan(...$steps);
    }
}
