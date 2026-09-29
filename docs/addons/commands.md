---
title: Commands and write actions
weight: 42
description: "Write a command, its WriteAction and its surfaces: the Envelope a surface builds, the aggregates and versions resolve() reads, and the WriteResult a write ends with."
---

# Commands and write actions

<!-- extension-point: Cbox\Cms\Contracts\Pipeline\Command -->
<!-- extension-point: Cbox\Cms\Contracts\Pipeline\WriteAction -->
<!-- extension-point: Cbox\Cms\Contracts\Pipeline\Aggregates -->
<!-- extension-point: Cbox\Cms\Contracts\Pipeline\AggregateRef -->
<!-- extension-point: Cbox\Cms\Contracts\Attributes\Action -->

There is one way to change state: a command, run through the kernel's command pipeline (PRD 6.1, 6.2). The panel, REST, MCP, agents, the CLI, jobs, the scheduler, subscribers, sidecars and seeds all call the same write action. All the types on this page are `#[Experimental]` and live in the contracts module.

A write has two parts:

- The **command** is the domain input: its targets, the versions the caller expected and its fields. It is a `final readonly class` that implements the marker interface `Cbox\Cms\Contracts\Pipeline\Command` and declares its name and version with `#[Command]` from `Cbox\Cms\Contracts\Attributes` (see [build declarations](build-declarations.md)). Both are named `Command`, so a file that uses both imports one under another name.
- The **envelope**, `Cbox\Cms\Contracts\Envelope\Envelope`, is everything else about the call. The surface builds it; an action never does.

## The envelope

| Part | Type | What |
|---|---|---|
| `surface` | `IssuingSurface` | where the call came from: an exposed surface (`Rest`, `Inertia`, `Mcp`, `Cli`) or an internal issuer (`Job`, `Scheduler`, `Subscriber`, `Sidecar`, `Seed`) |
| `issuerKind` | `IssuerKind` | human, agent, seed, migration, scheduler, sync or system (PRD 5.5) |
| `actor` | `ActorId` | who runs the command, from the transport's authentication, never from input fields |
| `onBehalfOf` | `OnBehalfOf` | the chain of principals the actor acts for, in order, from the one it acts for directly to the person at the end; `responsible()` gives that person, or the actor when the chain is empty |
| `idempotencyKey` | `IdempotencyKey` | the key the idempotency store claims (PRD 6.1) |
| `unitOfWork` | `?UnitOfWork` | the internal issuer's unit of work the key was derived from |
| `correlationId` | `CorrelationId` | ties the call together across HTTP, MCP, CLI, jobs and sidecars |
| `provenance` | `Provenance` | model, version, parameters, prompt reference and sources, for agents and ingestion |
| `reason` | `?Reason` | a `ReasonCode` and an optional `ReasonText`, where the command requires a reason |
| `dryRun` | `bool` | compute the plan and the receipt without committing; false by default |
| `waitLevel` | `WaitLevel` | how long the caller waits for its receipt; `WaitLevel::Commit` by default (PRD 8.4) |

An exposed surface builds the envelope with `Envelope::external()`, and the idempotency key its caller sent is required: there is no way to build an external envelope without one. An internal issuer has no caller to send a key, so it builds the envelope with `Envelope::internal()` from its `UnitOfWork`, such as `event:<event id>:<step>` or `seed:<seed>:<chunk>`, and the key is derived from it: the issuer, a colon and the SHA-256 of the unit (`Envelope::deriveKey()`). The same unit always gives the same key, so a job or a subscriber that runs the same unit again after a crash replays the first result instead of committing twice. `internal()` refuses an exposed surface, and `external()` an internal issuer. `idempotencyScope()` gives the scope the key is unique in: the actor and the command's name.

The reason's code goes into the audit chain and events: `auditReason()` gives it. The free text can hold personal data, so it is stored only as classified content on the changeset. `ReasonText` has no public property, is neither `Stringable` nor `JsonSerializable`, and is read on purpose with `classifiedContent()`; no mutation, plan, result or receipt type can hold it.

<!-- example: examples/Unit/Pipeline/EnvelopeTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Envelope\IssuerKind;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Envelope\OnBehalfOf;
use Cbox\Cms\Contracts\Envelope\UnitOfWork;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Ids\ActorId;

// A surface builds the envelope from what its transport authenticated and what the caller sent;
// an internal issuer builds it from its unit of work. The action never builds one.

it('carries the key a REST caller sent, and waits for commit by default', function (): void {
    $agent = ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-00000000000a');
    $editor = ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-00000000000b');

    $envelope = Envelope::external(
        IssuingSurface::Rest,
        IssuerKind::Agent,
        $agent,
        new IdempotencyKey('save-groceries-1'),
        new CorrelationId('4bf92f3577b34da6a3ce929d0e0e4736'),
        new OnBehalfOf($editor),
    );

    expect($envelope->idempotencyKey->value)->toBe('save-groceries-1')
        ->and($envelope->waitLevel)->toBe(WaitLevel::Commit)
        ->and($envelope->responsible()->equals($editor))->toBeTrue();
});

it('derives a subscriber\'s key from the event it handles, so a retry replays', function (): void {
    $system = ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-00000000000c');
    $unit = new UnitOfWork('event:01936f5e-8a2b-7c3d-9e4f-0000000000ff:reindex');

    $first = Envelope::internal(IssuingSurface::Subscriber, IssuerKind::System, $system, $unit, new CorrelationId('run-1'));
    $retry = Envelope::internal(IssuingSurface::Subscriber, IssuerKind::System, $system, $unit, new CorrelationId('run-2'));

    expect($first->idempotencyKey->equals($retry->idempotencyKey))->toBeTrue()
        ->and($first->idempotencyKey->value)->toStartWith('subscriber:');
});
```

## The write action

A write action implements `Cbox\Cms\Contracts\Pipeline\WriteAction`, a generic interface over its command and its aggregates, with two pure steps the kernel calls:

- `resolve(Command): Aggregates` reads the aggregates the command touches through the read ports the action gets in its constructor (phase 1).
- `plan(Command, Aggregates): Plan` computes the [plan](plans.md), the typed mutations, from the command and what was read (phase 3). It may compose the plans of other commands' planners.

Neither step writes, commits or calls another write action. The kernel owns the rest: authorization, hooks, validation, the dry-run exit, and the commit of every mutation, the audit, the events and the receipt in one transaction (PRD 6.2). An action is a `final readonly class`, and it states its type arguments with `@implements WriteAction<SaveNote, NoteAggregates>`, so PHPStan types `$command` and `$aggregates` in both methods.

What `resolve()` returns implements `Cbox\Cms\Contracts\Pipeline\Aggregates`: the action's own final readonly class with what it read, and `versions()`, the `ReadVersions` it read them at. Each `ReadVersion` names an aggregate by an `AggregateRef` and holds its `AggregateVersion`, or null when the aggregate did not exist, as for the note a create makes. At commit the kernel checks that every aggregate is still at the version it was read at, or still absent, and rejects the command with `version_conflict` otherwise, so a plan made from stale reads never commits. An aggregate is read at most once, and the reads are sorted by key.

`Cbox\Cms\Contracts\Pipeline\AggregateRef` is what names an aggregate: `aggregateKey()` gives its kind and id, such as `entry:<uuid>`, unique across every kind. The typed ids `EntryId`, `NodeId`, `PlacementId`, `SiteId` and `ActorId` implement it, and so does `VariantRef`, one variant of an entry (`variant:<uuid>:<variant>`).

This action saves a note's title. For a new note it reads the note as absent and plans the entry, its first revision, its head and, from another planner, its placement; for an existing note it reads the variant at its version and plans the next revision:

<!-- example-file: examples/Unit/Pipeline/SaveNote.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Pipeline;

use Cbox\Cms\Contracts\Attributes\Command as CommandType;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\Command;

/**
 * Saves a note's title: creates the note, placed below its home node, when it does not exist yet,
 * and otherwise writes a new revision. Version 1 of note.save.
 */
#[CommandType('note.save', version: 1)]
final readonly class SaveNote implements Command
{
    public function __construct(
        public EntryId $note,
        public TypeId $type,
        public NodeId $home,
        public SiteId $site,
        public string $title,
    ) {}
}
```

<!-- example-file: examples/Unit/Pipeline/NoteAggregates.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Pipeline;

use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * What SaveNoteAction::resolve() read: the note, or null when it does not exist yet. versions()
 * tells the kernel what to check at commit: the note's shared variant at the version it was read
 * at, or that the note is still absent.
 */
final readonly class NoteAggregates implements Aggregates
{
    public function __construct(
        public EntryId $note,
        public ?StoredNote $stored,
    ) {}

    #[Override]
    public function versions(): ReadVersions
    {
        return $this->stored instanceof StoredNote
            ? new ReadVersions(ReadVersion::at(new VariantRef($this->note, VariantKey::shared()), $this->stored->version))
            : new ReadVersions(ReadVersion::absent($this->note));
    }
}
```

<!-- example-file: examples/Unit/Pipeline/NotePlacements.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Pipeline;

use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementCreated;
use Cbox\Cms\Contracts\Plans\Plan;

/**
 * Another command's planner, which SaveNoteAction composes: the plan that places an entry below a
 * node. It computes a plan and nothing else; it never writes or calls another action.
 */
final readonly class NotePlacements
{
    public function place(PlacementId $placement, EntryId $note, NodeId $node, SiteId $site): Plan
    {
        return new Plan(new PlacementCreated($placement, $note, $node, $site));
    }
}
```

<!-- example-file: examples/Unit/Pipeline/SaveNoteAction.php -->
```php
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
 * The write action of note.save, exposed on REST and MCP. resolve() reads the note through the
 * shelf; plan() turns the command and what was read into mutations, and for a new note composes
 * the placement planner's plan. Neither writes: the kernel commits the plan. The kernel calls
 * the action only with the command and aggregates of its WriteAction type arguments.
 *
 * @implements WriteAction<SaveNote, NoteAggregates>
 */
#[Action(surfaces: [Surface::Rest, Surface::Mcp])]
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
        return new NoteAggregates($command->note, $this->shelf->find($command->note));
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
                new RevisionCreated($command->note, $shared, RevisionNumber::first(), $fields),
                new HeadMoved($command->note, $shared, null, RevisionNumber::first()),
            )->then($this->placements->place($this->newPlacement, $command->note, $command->home, $command->site));
        }

        $next = $aggregates->stored->head->next();

        return new Plan(
            new RevisionCreated($command->note, $shared, $next, $fields),
            new HeadMoved($command->note, $shared, $aggregates->stored->head, $next),
        );
    }
}
```

A write action is tested without a database or the kernel: call `resolve()`, then `plan()`, and check the reads and the mutations. The test is in the `Unit` suite:

<!-- example: examples/Unit/Pipeline/WriteActionTest.php -->
```php
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
        ->and($aggregates->versions()->reads)->toHaveCount(1)
        ->and($aggregates->versions()->of($command->note))->toEqual(ReadVersion::absent($command->note))
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
```

## Surfaces

`#[Action(surfaces: [...])]` on a write or query action lists the surfaces it is exposed on, cases of the enum `Cbox\Cms\Contracts\Attributes\Surface`:

| Surface | Value | The transport |
|---|---|---|
| `Surface::Rest` | `rest` | REST with JSON and problem details |
| `Surface::Inertia` | `inertia` | the panel's Inertia pages, with redirects and errors in page props |
| `Surface::Mcp` | `mcp` | MCP tools for agents |
| `Surface::Cli` | `cli` | `cms:*` commands, with exit codes from the [error catalog](errors.md) |

A surface is listed at most once, and `surfaces` is sorted in the order of the enum. An action with no surface, `#[Action(surfaces: [])]`, is called only by the internal issuers. A surface contains no logic: it builds the envelope and the command, calls the action through the kernel and translates the result.

<!-- example: examples/Unit/Pipeline/ActionAttributeTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Command;
use Cbox\Cms\Contracts\Attributes\Surface;
use Examples\Unit\Pipeline\FindNoteTitleAction;
use Examples\Unit\Pipeline\SaveNote;
use Examples\Unit\Pipeline\SaveNoteAction;

// What the build reads from the declarations: the command's name and version from #[Command],
// and each action's surfaces from #[Action], sorted in the order of the Surface enum.

it('declares the command and the surfaces of each action', function (): void {
    $command = new ReflectionClass(SaveNote::class)->getAttributes(Command::class)[0]->newInstance();
    $write = new ReflectionClass(SaveNoteAction::class)->getAttributes(Action::class)[0]->newInstance();
    $query = new ReflectionClass(FindNoteTitleAction::class)->getAttributes(Action::class)[0]->newInstance();

    expect($command->name()->value)->toBe('note.save')
        ->and($command->version)->toBe(1)
        ->and($write->surfaces)->toBe([Surface::Rest, Surface::Mcp])
        ->and($write->exposes(Surface::Cli))->toBeFalse()
        ->and($query->surfaces)->toBe([Surface::Rest, Surface::Inertia]);
});
```

## The result of a write

A write ends in a `Cbox\Cms\Contracts\Results\WriteResult`, which carries the call's `Receipt` and what belongs to its outcome (PRD 6.1):

| Outcome | What the result carries |
|---|---|
| `rejected` | at least one `CatalogError`: a code from the error catalog, the `FieldPath` of the input it is about or null, and the cause in plain language. Nothing was committed. |
| `committed` | the receipt with the changeset |
| `committed_wait_timeout` | the receipt with the changeset; the wait level was not reached in time |
| `dry_run` | the plan the command would have committed |

A `FieldPath` is a list of names and indexes, written `blocks[2].text` or `fields.ext.app.tax_code`. Each surface translates the result for its transport. No code serialises a result to JSON; the codecs generated for the contracts give it its JSON form.
