<?php

declare(strict_types=1);

namespace Examples\Unit\Pipeline;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Mutations\EntryCreated;
use Cbox\Cms\Contracts\Plans\Mutations\HeadMoved;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Contracts\Plans\Plan;
use Override;

/**
 * The write action of note.save, exposed on REST and MCP; cms:build registers it for the command
 * SaveNote declares. resolve() reads the note through the shelf; plan() turns the command and what
 * was read into mutations, and for a new note composes the placement planner's plan. Neither
 * writes: the kernel commits the plan. The kernel calls the action only with the command and
 * aggregates of its WriteAction type arguments.
 *
 * @implements WriteAction<SaveNote, NoteAggregates>
 */
#[Action(handles: SaveNote::class, surfaces: [Surface::Rest, Surface::Mcp])]
final readonly class SaveNoteAction implements WriteAction
{
    public function __construct(
        private NoteShelf $shelf,
        private NotePlacements $placements,
        private PlacementId $newPlacement,
    ) {}

    /**
     * @param  SaveNote  $command
     */
    #[Override]
    public function resolve(Command $command): NoteAggregates
    {
        return new NoteAggregates($command->note, $this->shelf->find($command->note), $this->newPlacement);
    }

    /**
     * @param  SaveNote  $command
     * @param  NoteAggregates  $aggregates
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        $shared = VariantKey::shared();
        $fields = new FieldValues(new FieldMap(new NamedValue(new FieldHandle('title'), new TextValue($command->title))));

        if (! $aggregates->stored instanceof StoredNote) {
            return new Plan(
                new EntryCreated($command->note, $command->type, $command->home),
                new RevisionCreated($command->note, $command->type, $shared, RevisionNumber::first(), $fields),
                new HeadMoved($command->note, $shared, null, RevisionNumber::first()),
            )->then($this->placements->place($this->newPlacement, $command->note, $command->home, $command->site));
        }

        $next = $aggregates->stored->head->next();

        return new Plan(
            new RevisionCreated($command->note, $command->type, $shared, $next, $fields),
            new HeadMoved($command->note, $shared, $aggregates->stored->head, $next),
        );
    }
}
