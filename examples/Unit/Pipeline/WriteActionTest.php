<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Plans\Mutation;
use Cbox\Cms\Contracts\Plans\Mutations\HeadMoved;
use Examples\Unit\Pipeline\NoteAggregates;
use Examples\Unit\Pipeline\NotePlacements;
use Examples\Unit\Pipeline\NoteShelf;
use Examples\Unit\Pipeline\SaveNote;
use Examples\Unit\Pipeline\SaveNoteAction;
use Examples\Unit\Pipeline\StoredNote;

// A write action is tested by calling its two pure steps directly: resolve() with the command,
// then plan() with the command and what resolve() read. No database, no kernel.

function saveNoteCommand(string $title): SaveNote
{
    return new SaveNote(
        EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000001'),
        TypeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000002'),
        NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000003'),
        SiteId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000004'),
        $title,
    );
}

function saveNoteAction(StoredNote ...$notes): SaveNoteAction
{
    return new SaveNoteAction(
        new NoteShelf(...$notes),
        new NotePlacements,
        PlacementId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000005'),
    );
}

/**
 * @return list<string>
 */
function mutationNames(Mutation ...$mutations): array
{
    return array_map(static fn (Mutation $mutation): string => new ReflectionClass($mutation)->getShortName(), array_values($mutations));
}

it('creates, places and heads a new note, and reads it as absent', function (): void {
    $command = saveNoteCommand('Groceries');
    $action = saveNoteAction();

    $aggregates = $action->resolve($command);
    $plan = $action->plan($command, $aggregates);

    expect($aggregates)->toBeInstanceOf(NoteAggregates::class)
        ->and($aggregates->versions()->reads)->toHaveCount(3)
        ->and($aggregates->versions()->of($command->note))->toEqual(ReadVersion::absent($command->note))
        ->and(array_map(static fn (Mutation $mutation): bool => $aggregates->versions()->of($mutation->aggregate()) instanceof ReadVersion, $plan->mutations()))->toBe([true, true, true, true])
        ->and(mutationNames(...$plan->mutations()))->toBe(['EntryCreated', 'RevisionCreated', 'HeadMoved', 'PlacementCreated']);
});

it('writes the next revision of an existing note and reads its variant at its version', function (): void {
    $command = saveNoteCommand('Groceries for Sunday');
    $action = saveNoteAction(new StoredNote($command->note, new AggregateVersion(4), new RevisionNumber(3)));

    $aggregates = $action->resolve($command);
    $plan = $action->plan($command, $aggregates);
    $head = $plan->mutations()[1];

    expect($aggregates->versions()->reads[0]->version?->value)->toBe(4)
        ->and(mutationNames(...$plan->mutations()))->toBe(['RevisionCreated', 'HeadMoved'])
        ->and($head)->toBeInstanceOf(HeadMoved::class)
        ->and($head instanceof HeadMoved ? [$head->from?->value, $head->to->value] : [])->toBe([3, 4]);
});
