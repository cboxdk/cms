<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Core\Entries\Domain\Commands\ReleaseEntryVariant;
use Cbox\Cms\Core\Entries\Domain\Dto\ReleaseEntryVariantAggregates;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredEntry;
use Cbox\Cms\Core\Entries\Domain\EntryReader;
use LogicException;
use Override;

/**
 * The write action of variant.release version 2 (PRD 5.6, 6.2, 6.4, 10.3), exposed on every
 * surface. resolve() reads the entry with the head of the variant the command names, the shared
 * one or a language's; plan() is the VariantReleasePlanner's plan for that variant and the
 * revision the command names.
 *
 * The command expects the variant at a version, so the kernel rejects it with version_conflict
 * before plan() when the entry or its variant is absent or at another version; plan() is only ever
 * called with the head the caller saw.
 *
 * @implements WriteAction<ReleaseEntryVariant, ReleaseEntryVariantAggregates>
 */
#[Action(handles: ReleaseEntryVariant::class, surfaces: [Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli])]
#[Internal]
final readonly class ReleaseEntryVariantAction implements WriteAction
{
    public function __construct(
        private EntryReader $entries,
        private TypeCatalog $types,
        private VariantReleasePlanner $planner,
    ) {}

    /**
     * @param  ReleaseEntryVariant  $command
     */
    #[Override]
    public function resolve(Command $command): ReleaseEntryVariantAggregates
    {
        $stored = $this->entries->entry($command->entry, $command->variant);

        return new ReleaseEntryVariantAggregates($command->ref(), $stored, $stored instanceof StoredEntry ? $this->types->find($stored->type) : null);
    }

    /**
     * @param  ReleaseEntryVariant  $command
     * @param  ReleaseEntryVariantAggregates  $aggregates
     *
     * @throws LogicException when the entry was read as absent, which the kernel's check of the expected version rules out
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        if (! $aggregates->stored instanceof StoredEntry) {
            throw new LogicException(sprintf(
                'variant.release planned a release of the variant %s of the entry %s, which was read as absent; the kernel rejects such a call with version_conflict before it plans.',
                $command->variant->value,
                $command->entry->toString(),
            ));
        }

        return $this->planner->plan($aggregates->stored, $command->revision, $command->variant);
    }
}
