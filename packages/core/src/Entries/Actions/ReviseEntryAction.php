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
use Cbox\Cms\Contracts\Plans\Mutations\HeadMoved;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Core\Entries\Domain\Commands\ReviseEntry;
use Cbox\Cms\Core\Entries\Domain\Dto\ReviseEntryAggregates;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredEntry;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredHead;
use Cbox\Cms\Core\Entries\Domain\EntryReader;
use LogicException;
use Override;

/**
 * The write action of entry.revise (PRD 5.4, 6.2), exposed on every surface. resolve() reads the
 * entry with the head of its shared variant; plan() writes the revision after the variant's highest
 * number, which is the head's draft or a published revision a release wrote after it, and moves the
 * head from its draft to the new revision, for the entry's own type.
 *
 * The command expects the variant at a version, so the kernel rejects it with version_conflict
 * before plan() when the entry or its variant is absent or at another version; plan() is only ever
 * called with the head the caller saw.
 *
 * @implements WriteAction<ReviseEntry, ReviseEntryAggregates>
 */
#[Action(handles: ReviseEntry::class, surfaces: [Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli])]
#[Internal]
final readonly class ReviseEntryAction implements WriteAction
{
    public function __construct(private EntryReader $entries) {}

    /**
     * @param  ReviseEntry  $command
     */
    #[Override]
    public function resolve(Command $command): ReviseEntryAggregates
    {
        return new ReviseEntryAggregates($command->entry, $this->entries->entry($command->entry, VariantKey::shared()));
    }

    /**
     * @param  ReviseEntry  $command
     * @param  ReviseEntryAggregates  $aggregates
     *
     * @throws LogicException when the variant was read as absent, which the kernel's check of the expected version rules out
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        $stored = $aggregates->stored;
        $head = $stored?->head;

        if (! $stored instanceof StoredEntry || ! $head instanceof StoredHead) {
            throw new LogicException(sprintf(
                'entry.revise planned a revision of the entry %s, whose shared variant was read as absent; the kernel rejects such a call with version_conflict before it plans.',
                $command->entry->toString(),
            ));
        }

        $next = $head->latest->next();
        $shared = VariantKey::shared();

        return new Plan(
            new RevisionCreated($command->entry, $stored->type, $shared, $next, $command->fields),
            new HeadMoved($command->entry, $shared, $head->revision, $next),
        );
    }
}
