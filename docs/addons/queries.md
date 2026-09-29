---
title: Queries and query actions
weight: 44
description: "Write a query, its QueryAction and its typed Result, which the query pipeline calls after it has set the actor's context and authorized the read."
---

# Queries and query actions

<!-- extension-point: Cbox\Cms\Contracts\Pipeline\Query -->
<!-- extension-point: Cbox\Cms\Contracts\Pipeline\Result -->
<!-- extension-point: Cbox\Cms\Contracts\Pipeline\QueryAction -->

A read goes through the query pipeline (PRD 6.2): it takes the actor from the transport's authentication and refuses credentials of an actor that is not active, sets the actor's context and row-level security, authorizes the read, checks its cost budget, runs it on the primary or a replica, strips fields above the actor's classification and writes the read audit where the classification requires it. Only then does it call the query action. All the types on this page are `#[Experimental]`.

- A **query** is the input of a read: a `final readonly class` that implements the marker `Cbox\Cms\Contracts\Pipeline\Query` and declares its name and version with `#[Query('note.find_title', version: 1)]` from `Cbox\Cms\Contracts\Attributes`. The name has the form of a command name, and commands and queries share the names: a name and version belong to one command or one query. The actor is never a field of a query.
- A **result** is the typed output: a `final readonly` DTO that implements the marker `Cbox\Cms\Contracts\Pipeline\Result`, typically a named selection of fields (PRD 8.9).
- A **query action** implements `Cbox\Cms\Contracts\Pipeline\QueryAction`, a generic interface over its query and its result, with one method, `handle(Query): Result`. It reads through the read ports it gets in its constructor and never writes. It states its type arguments with `@implements QueryAction<FindNoteTitle, NoteTitle>`, and names the query it handles and its surfaces with `#[Action(handles: FindNoteTitle::class, surfaces: [...])]`, as a write action does (see [commands and write actions](commands.md)). `cms:build` registers it in `actions.php` under the query's name and version.

<!-- example-file: examples/Unit/Pipeline/FindNoteTitle.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Pipeline;

use Cbox\Cms\Contracts\Attributes\Query as QueryType;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\Query;

/**
 * Asks for the title of a note, version 1 of note.find_title. The actor is not a field: the query
 * pipeline takes it from the transport's authentication.
 */
#[QueryType('note.find_title', version: 1)]
final readonly class FindNoteTitle implements Query
{
    public function __construct(
        public EntryId $note,
    ) {}
}
```

<!-- example-file: examples/Unit/Pipeline/NoteTitle.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Pipeline;

use Cbox\Cms\Contracts\Pipeline\Result;

/**
 * The result of note.find_title: the title, or null when there is no such note.
 */
final readonly class NoteTitle implements Result
{
    public function __construct(
        public ?string $title,
    ) {}
}
```

<!-- example-file: examples/Unit/Pipeline/FindNoteTitleAction.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Pipeline;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Override;

/**
 * The query action behind the note title, exposed on REST and in the panel. It reads and returns
 * the typed result; the query pipeline has already set the actor's context and authorized it.
 *
 * @implements QueryAction<FindNoteTitle, NoteTitle>
 */
#[Action(handles: FindNoteTitle::class, surfaces: [Surface::Inertia, Surface::Rest])]
final readonly class FindNoteTitleAction implements QueryAction
{
    public function __construct(
        private NoteTitles $titles,
    ) {}

    /**
     * @param  FindNoteTitle  $query
     */
    #[Override]
    public function handle(Query $query): NoteTitle
    {
        return new NoteTitle($this->titles->of($query->note));
    }
}
```

A query action is tested by calling `handle()` with the query. The test is in the `Unit` suite:

<!-- example: examples/Unit/Pipeline/QueryActionTest.php -->
```php
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
```
