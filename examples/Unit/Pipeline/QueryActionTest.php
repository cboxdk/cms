<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Ids\EntryId;
use Examples\Unit\Pipeline\FindNoteTitle;
use Examples\Unit\Pipeline\FindNoteTitleAction;
use Examples\Unit\Pipeline\NoteTitle;
use Examples\Unit\Pipeline\NoteTitles;

// A query action is tested by calling handle() with the query and checking the typed result.

it('returns the note\'s title as a typed result', function (): void {
    $note = EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000001');
    $action = new FindNoteTitleAction(new NoteTitles([$note->toString() => 'Groceries']));

    expect($action->handle(new FindNoteTitle($note)))->toEqual(new NoteTitle('Groceries'))
        ->and($action->handle(new FindNoteTitle(EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000009')))->title)->toBeNull();
});
