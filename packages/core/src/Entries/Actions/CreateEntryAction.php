<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Mutations\EntryCreated;
use Cbox\Cms\Contracts\Plans\Mutations\HeadMoved;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateEntry;
use Cbox\Cms\Core\Entries\Domain\Dto\CreateEntryAggregates;
use Cbox\Cms\Core\Entries\Domain\EntryReader;
use Override;

/**
 * The write action of entry.create (PRD 5.4, 6.2), exposed on every surface. resolve() reads the
 * entry, which the command expects to be absent, and the home node; plan() creates the entry, the
 * first revision of its shared variant and the variant's head on it. It works for any type from
 * its schema: the kernel validates the fields against the type the command names, and the writers
 * store the revision and the type table's row as the type's capabilities say.
 *
 * @implements WriteAction<CreateEntry, CreateEntryAggregates>
 */
#[Action(handles: CreateEntry::class, surfaces: [Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli])]
#[Internal]
final readonly class CreateEntryAction implements WriteAction
{
    public function __construct(private EntryReader $entries) {}

    /**
     * @param  CreateEntry  $command
     */
    #[Override]
    public function resolve(Command $command): CreateEntryAggregates
    {
        return new CreateEntryAggregates(
            $command->entry,
            $this->entries->entry($command->entry, VariantKey::shared()),
            $command->home,
            $this->entries->node($command->home),
        );
    }

    /**
     * @param  CreateEntry  $command
     * @param  CreateEntryAggregates  $aggregates
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        $shared = VariantKey::shared();
        $first = RevisionNumber::first();

        return new Plan(
            new EntryCreated($command->entry, $command->type, $command->home),
            new RevisionCreated($command->entry, $command->type, $shared, $first, $command->fields),
            new HeadMoved($command->entry, $shared, null, $first),
        );
    }
}
