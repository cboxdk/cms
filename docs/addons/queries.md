---
title: Queries and query actions
weight: 44
description: "Write a query, its QueryAction and its typed Result, which the query pipeline calls after it has set the actor's context and authorized the read."
---

# Queries and query actions

<!-- extension-point: Cbox\Cms\Contracts\Pipeline\Query -->
<!-- extension-point: Cbox\Cms\Contracts\Pipeline\Result -->
<!-- extension-point: Cbox\Cms\Contracts\Pipeline\QueryAction -->
<!-- extension-point: Cbox\Cms\Contracts\Pipeline\ReadsContent -->
<!-- extension-point: Cbox\Cms\Contracts\Pipeline\PublicQuery -->
<!-- extension-point: Cbox\Cms\Contracts\Pipeline\ActorQuery -->

A read goes through the query pipeline (PRD 6.2), in one transaction on the primary. It takes the actor from the credential the transport carried, or reads as the anonymous principal without one, and refuses a credential of an actor that is not active. It sets the actor's context for row-level security with `SET LOCAL`, authorizes the read and checks its cost against the principal's budget. Only then does it call the query action. Afterwards it strips the fields above the actor's classification access, writes the read audit where a field's classification requires it, and answers with the result, the content keys and the read's position. The context ends with the transaction, so nothing of one read is left for the next on the same worker. All the types on this page are `#[Experimental]`.

- A **query** is the input of a read: a `final readonly class` that implements the marker `Cbox\Cms\Contracts\Pipeline\Query` and declares its name and version with `#[Query('note.find_title', version: 1)]` from `Cbox\Cms\Contracts\Attributes`. The name has the form of a command name, and commands and queries share the names: a name and version belong to one command or one query. The actor is never a field of a query.
- A read is authorized by the actor's roles: an actor may run it only through a role whose permissions name it and that reaches some node, and the anonymous principal runs none. A query that anyone may run, the anonymous principal included, implements `Cbox\Cms\Contracts\Pipeline\PublicQuery`, which extends `Query`; the kernel's `path.resolve` is one (invariant 25). A query that every actor may run without a permission, because what it reads is bounded by the actor's own context, implements `Cbox\Cms\Contracts\Pipeline\ActorQuery`, which extends `Query`; the kernel's `node.list`, the nodes the actor's regions reach, is one, and the anonymous principal may not run it. Either way, row-level security under the principal's context decides which rows the read reaches, so the anonymous principal reads only what is public.
- A **result** is the typed output: a `final readonly` DTO that implements the marker `Cbox\Cms\Contracts\Pipeline\Result`, typically a named selection of fields (PRD 8.9).
- A **query action** implements `Cbox\Cms\Contracts\Pipeline\QueryAction`, a generic interface over its query and its result, with two methods. `cost(Query): QueryCost` states what the query costs, from the query alone and before anything is read: the rows it may return, how deep it reads and the relations it expands (PRD 8.8). A read that costs more than its principal's budget, `cbox-cms.queries.budgets.anonymous` or `cbox-cms.queries.budgets.actor`, is rejected with `query_over_budget`. `handle(Query): Result` reads through the read ports the action gets in its constructor and never writes. The action states its type arguments with `@implements QueryAction<FindNoteTitle, NoteTitle>`, and names the query it handles and its surfaces with `#[Action(handles: FindNoteTitle::class, surfaces: [...])]`, as a write action does (see [commands and write actions](commands.md)). `cms:build` registers it in `actions.php` under the query's name and version.
- A result that carries the fields of entries implements `Cbox\Cms\Contracts\Pipeline\ReadsContent`. Each entry is a `Cbox\Cms\Contracts\Results\ReadContent`: the entry, the node the read reached it through, its type and its fields. The pipeline takes the entries from `contents()`, leaves out every field above the principal's classification access and every field the type does not declare, and puts them back with `withContents()`, which holds exactly the entries it is given. For a credential issued for an agent it also leaves out every field the blueprint does not open to agents (PRD 2.31, 12.2): a `public` or `internal` field that says `agents: false`, a `confidential` field without `agents: true`, and every `personal` and `sensitive` field, nested fields of a group included. A read through MCP needs such a credential (see [commands](commands.md#the-mcp-surface)). It records the read audit for the sensitive fields that are left, and answers with the content keys `e-{entry}` and `n-{node}` of every entry (PRD 9.4).

The pipeline answers with a `Cbox\Cms\Contracts\Results\QueryResult`: the stripped result, its content keys and its position when it answered, or the catalog errors when it did not.

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
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Override;

/**
 * The query action behind the note title, exposed on REST and in the panel. It states what a query
 * costs before anything is read, and reads and returns the typed result; the query pipeline has
 * already set the actor's context, authorized the read and checked the cost against the budget.
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
     * One note by its id.
     */
    #[Override]
    public function cost(Query $query): QueryCost
    {
        return new QueryCost(1);
    }

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

// A query action is tested by calling cost() and handle() with the query and checking the cost and
// the typed result.

it('returns the note\'s title as a typed result, at a cost of one', function (): void {
    $note = EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000001');
    $action = new FindNoteTitleAction(new NoteTitles([$note->toString() => 'Groceries']));

    expect($action->cost(new FindNoteTitle($note))->units)->toBe(1)
        ->and($action->handle(new FindNoteTitle($note)))->toEqual(new NoteTitle('Groceries'))
        ->and($action->handle(new FindNoteTitle(EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000009')))->title)->toBeNull();
});
```

A result that carries content is tested the same way, through `contents()` and `withContents()`:

<!-- example-file: examples/Unit/Pipeline/NoteCards.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Pipeline;

use Cbox\Cms\Contracts\Pipeline\ReadsContent;
use Cbox\Cms\Contracts\Results\ReadContent;
use Override;

/**
 * A result that carries the fields of the notes it read. The query pipeline takes the notes from
 * contents(), strips the fields the principal may not read, and puts them back with
 * withContents(), so the caller only ever gets the stripped notes.
 */
final readonly class NoteCards implements ReadsContent
{
    /**
     * @param  list<ReadContent>  $notes
     */
    public function __construct(
        public array $notes,
    ) {}

    /**
     * @return list<ReadContent>
     */
    #[Override]
    public function contents(): array
    {
        return $this->notes;
    }

    /**
     * @param  list<ReadContent>  $contents
     */
    #[Override]
    public function withContents(array $contents): static
    {
        return new self($contents);
    }
}
```

<!-- example: examples/Unit/Pipeline/ReadsContentTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Results\ReadContent;
use Examples\Unit\Pipeline\NoteCards;

// A result that implements ReadsContent hands its notes to the pipeline and takes them back with
// only their fields changed; each note gives the content keys e-{entry} and n-{node}.

it('gives its notes and holds the notes it is given back, with their content keys', function (): void {
    $note = new ReadContent(
        EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000001'),
        NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000002'),
        TypeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000003'),
        new FieldValues(new FieldMap(
            new NamedValue(new FieldHandle('title'), new TextValue('Groceries')),
            new NamedValue(new FieldHandle('diagnosis'), new TextValue('Private')),
        )),
    );
    $stripped = $note->withFields(new FieldValues(new FieldMap(new NamedValue(new FieldHandle('title'), new TextValue('Groceries')))));

    $cards = new NoteCards([$note])->withContents([$stripped]);

    expect($cards->contents())->toBe([$stripped])
        ->and($cards->notes[0]->fields->own->handles())->toEqual([new FieldHandle('title')])
        ->and(array_map(static fn (DependencyKey $key): string => $key->toString(), $note->contentKeys()))->toBe([
            'e-01936f5e-8a2b-7c3d-9e4f-000000000001',
            'n-01936f5e-8a2b-7c3d-9e4f-000000000002',
        ]);
});
```
