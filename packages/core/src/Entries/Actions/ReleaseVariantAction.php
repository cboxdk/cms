<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Core\Entries\Domain\Commands\ReleaseVariant;
use Cbox\Cms\Core\Entries\Domain\Dto\ReleaseVariantAggregates;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredEntry;
use Cbox\Cms\Core\Entries\Domain\EntryReader;
use LogicException;
use Override;

/**
 * The write action of variant.release (PRD 5.6, 6.2, 6.4), exposed on every surface. resolve()
 * reads the entry with the head of its shared variant; plan() is the VariantReleasePlanner's plan
 * for the revision the command names.
 *
 * The command expects the variant at a version, so the kernel rejects it with version_conflict
 * before plan() when the entry or its variant is absent or at another version; plan() is only ever
 * called with the head the caller saw.
 *
 * @implements WriteAction<ReleaseVariant, ReleaseVariantAggregates>
 */
#[Action(handles: ReleaseVariant::class, surfaces: [Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli])]
#[Internal]
final readonly class ReleaseVariantAction implements WriteAction
{
    public function __construct(
        private EntryReader $entries,
        private VariantReleasePlanner $planner,
    ) {}

    /**
     * @param  ReleaseVariant  $command
     */
    #[Override]
    public function resolve(Command $command): ReleaseVariantAggregates
    {
        return new ReleaseVariantAggregates($command->entry, $this->entries->entry($command->entry, VariantKey::shared()));
    }

    /**
     * @param  ReleaseVariant  $command
     * @param  ReleaseVariantAggregates  $aggregates
     *
     * @throws LogicException when the entry was read as absent, which the kernel's check of the expected version rules out
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        if (! $aggregates->stored instanceof StoredEntry) {
            throw new LogicException(sprintf(
                'variant.release planned a release of the entry %s, which was read as absent; the kernel rejects such a call with version_conflict before it plans.',
                $command->entry->toString(),
            ));
        }

        return $this->planner->plan($aggregates->stored, $command->revision);
    }
}
