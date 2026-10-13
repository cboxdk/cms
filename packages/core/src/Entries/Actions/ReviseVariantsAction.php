<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Mutations\HeadMoved;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Core\Entries\Domain\Commands\ReviseVariants;
use Cbox\Cms\Core\Entries\Domain\Dto\ReviseVariantsAggregates;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredHead;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredVariants;
use Cbox\Cms\Core\Entries\Domain\EntryReader;
use LogicException;
use Override;

/**
 * The write action of entry.revise version 2 (PRD 5.4, 6.2, 10.3), exposed on every surface.
 * resolve() reads the entry with the head of each variant the command revises, in one lookup;
 * plan() writes, for each variant, the revision after the variant's highest number, which is the
 * head's draft or a published revision a release wrote after it, and moves the head from its draft
 * to the new revision, for the entry's own type: every revision, then every move, so the commit
 * writes each step of all the variants at once.
 *
 * The command expects each variant at a version, so the kernel rejects it with version_conflict
 * before plan() when the entry or a variant is absent or at another version, and the kernel's
 * shape check refuses a variant the type does not hold; plan() is only ever called with the heads
 * the caller saw.
 *
 * @implements WriteAction<ReviseVariants, ReviseVariantsAggregates>
 */
#[Action(handles: ReviseVariants::class, surfaces: [Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli])]
#[Internal]
final readonly class ReviseVariantsAction implements WriteAction
{
    public function __construct(
        private EntryReader $entries,
        private TypeCatalog $types,
    ) {}

    /**
     * @param  ReviseVariants  $command
     */
    #[Override]
    public function resolve(Command $command): ReviseVariantsAggregates
    {
        $refs = $command->refs();
        $stored = $this->entries->variants($command->entry, array_map(static fn (VariantRef $ref): VariantKey => $ref->variant, $refs));

        return new ReviseVariantsAggregates($command->entry, $refs, $stored, $stored instanceof StoredVariants ? $this->types->find($stored->type) : null);
    }

    /**
     * @param  ReviseVariants  $command
     * @param  ReviseVariantsAggregates  $aggregates
     *
     * @throws LogicException when the entry or a variant was read as absent, which the kernel's check of the expected versions rules out
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        $stored = $aggregates->stored ?? throw new LogicException(sprintf(
            'entry.revise planned revisions of the entry %s, which was read as absent; the kernel rejects such a call with version_conflict before it plans.',
            $command->entry->toString(),
        ));
        $revisions = [];
        $moves = [];

        foreach ($command->variants as $variant) {
            $head = $stored->head($variant->variant);

            if (! $head instanceof StoredHead) {
                throw new LogicException(sprintf(
                    'entry.revise planned a revision of the variant %s of the entry %s, which was read as absent; the kernel rejects such a call with version_conflict before it plans.',
                    $variant->variant->value,
                    $command->entry->toString(),
                ));
            }

            $next = $head->latest->next();
            $revisions[] = new RevisionCreated($command->entry, $stored->type, $variant->variant, $next, $variant->fields);
            $moves[] = new HeadMoved($command->entry, $variant->variant, $head->revision, $next);
        }

        return new Plan(...$revisions, ...$moves);
    }
}
