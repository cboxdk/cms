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
<!-- extension-point: Cbox\Cms\Contracts\Pipeline\ExpectsVersions -->
<!-- extension-point: Cbox\Cms\Contracts\Pipeline\RefusesCommand -->
<!-- extension-point: Cbox\Cms\Contracts\Pipeline\ReportsVisibility -->
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

Neither step writes, commits or calls another write action. The kernel owns the rest: authorization, hooks, validation, the dry-run exit, and the commit of every mutation, the audit, the events and the receipt in one transaction (PRD 6.2). An action is a `final readonly class`, and it states its type arguments with `@implements WriteAction<SaveNote, NoteAggregates>`, so PHPStan types `$command` and `$aggregates` in both methods. It names the command it handles with `#[Action(handles: SaveNote::class)]`, which `cms:build` reads (see [surfaces](#surfaces)).

What `resolve()` returns implements `Cbox\Cms\Contracts\Pipeline\Aggregates`: the action's own final readonly class with what it read, and `versions()`, the `ReadVersions` it read them at. Each `ReadVersion` names an aggregate by an `AggregateRef` and holds its `AggregateVersion`, or null when the aggregate did not exist, as for the note a create makes. At commit the kernel checks that every aggregate is still at the version it was read at, or still absent, and rejects the command with `version_conflict` otherwise, so a plan made from stale reads never commits. An aggregate is read at most once, and the reads are sorted by key. Every aggregate a mutation of the plan changes must be among the reads, a created one as absent; the kernel refuses a plan that changes an aggregate its `resolve()` did not read.

A command that carries the versions its caller saw implements `Cbox\Cms\Contracts\Pipeline\ExpectsVersions`, which extends `Command`: `expectedVersions()` gives a `ReadVersions` of the aggregates the caller read, each at its version or absent. The kernel compares them with what `resolve()` read and rejects the command with `version_conflict` when one differs, before it authorizes anything (invariant 11). Each aggregate a command expects a version of must be one its action reads.

A write action whose command what it read can rule out also implements `Cbox\Cms\Contracts\Pipeline\RefusesCommand`: `refusals(Command, Aggregates)` gives the errors to reject the call with, first the one that decides it, as `CatalogError`s of the [error catalog](errors.md), or none to go on. The kernel asks it after the authorize phase and before `plan()`, so a caller that may not run the command learns nothing from its reasons. It is pure like the other two steps; the kernel's `placement.create` refuses a slug another placement has below the node with it (see [placement commands](placement-commands.md)).

A write action whose plan can make placements visible that it does not change also implements `Cbox\Cms\Contracts\Pipeline\ReportsVisibility`: `becomesVisible(Command, Aggregates, Plan)` gives each placement in each locale the plan makes visible, or visible at another time, as a `BecomesVisible` with the time it becomes visible. The kernel asks it only on a dry run, with the plan as the hooks left it, and puts the list in the `DryRunReport`. It is pure like the other steps; the kernel's `entry.publish` lists the placements its release shows with it (see [publish commands](publish-commands.md)).

`Cbox\Cms\Contracts\Pipeline\AggregateRef` is what names an aggregate: `aggregateKey()` gives its kind and id, such as `entry:<uuid>`, unique across every kind. The typed ids `EntryId`, `NodeId`, `PlacementId`, `SiteId` and `ActorId` implement it, and so does `VariantRef`, one variant of an entry (`variant:<uuid>:<variant>`).

This action saves a note's title. For a new note it reads the note, its shared variant and its placement as absent and plans the entry, its first revision, its head and, from another planner, its placement; for an existing note it reads the variant at its version and plans the next revision:

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
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * What SaveNoteAction::resolve() read: the note, or null when it does not exist yet, and the
 * placement a new note gets. versions() tells the kernel what to check at commit, and names every
 * aggregate the plan changes, as the kernel requires: the note's shared variant at the version it
 * was read at, or that the note, its shared variant and its placement are still absent.
 */
final readonly class NoteAggregates implements Aggregates
{
    public function __construct(
        public EntryId $note,
        public ?StoredNote $stored,
        public PlacementId $placement,
    ) {}

    #[Override]
    public function versions(): ReadVersions
    {
        $shared = new VariantRef($this->note, VariantKey::shared());

        return $this->stored instanceof StoredNote
            ? new ReadVersions(ReadVersion::at($shared, $this->stored->version))
            : new ReadVersions(ReadVersion::absent($this->note), ReadVersion::absent($shared), ReadVersion::absent($this->placement));
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
```

## Surfaces

`#[Action(handles: ..., surfaces: [...])]` sits on every write and query action. `handles` is the class the action handles: for a write action a command declared with `#[Command]`, for a query action a query declared with `#[Query]` (see [queries](queries.md)). `cms:build` registers the action in `actions.php` under that command's or query's name and version, so the kernel finds the action for a command, and one command or query has one action (see [build declarations](build-declarations.md)). `surfaces` lists the surfaces the action is exposed on, cases of the enum `Cbox\Cms\Contracts\Attributes\Surface`:

| Surface | Value | The transport |
|---|---|---|
| `Surface::Rest` | `rest` | REST with JSON and problem details |
| `Surface::Inertia` | `inertia` | the panel's Inertia pages, with redirects and errors in page props |
| `Surface::Mcp` | `mcp` | MCP tools for agents |
| `Surface::Cli` | `cli` | `cms:*` commands, with exit codes from the [error catalog](errors.md) |

A surface is listed at most once, and `surfaces` is sorted in the order of the enum. Anything that is not a case of the enum, such as the string `'rest'`, throws `Cbox\Cms\Contracts\Attributes\UnknownSurface`, which `cms:build` reports as `registry_unknown_surface`. An action with no surface, `#[Action(handles: SaveNote::class)]`, is called only by the internal issuers. A surface contains no logic: it builds the envelope and the command, calls the action through the kernel and translates the result.

<!-- example: examples/Unit/Pipeline/ActionAttributeTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Command;
use Cbox\Cms\Contracts\Attributes\Query;
use Cbox\Cms\Contracts\Attributes\Surface;
use Examples\Unit\Pipeline\FindNoteTitle;
use Examples\Unit\Pipeline\FindNoteTitleAction;
use Examples\Unit\Pipeline\SaveNote;
use Examples\Unit\Pipeline\SaveNoteAction;

// What the build reads from the declarations: the name and version of the command from #[Command]
// and of the query from #[Query], and each action's class it handles and its surfaces from
// #[Action], sorted in the order of the Surface enum.

it('declares what each action handles and the surfaces it is exposed on', function (): void {
    $command = new ReflectionClass(SaveNote::class)->getAttributes(Command::class)[0]->newInstance();
    $query = new ReflectionClass(FindNoteTitle::class)->getAttributes(Query::class)[0]->newInstance();
    $write = new ReflectionClass(SaveNoteAction::class)->getAttributes(Action::class)[0]->newInstance();
    $read = new ReflectionClass(FindNoteTitleAction::class)->getAttributes(Action::class)[0]->newInstance();

    expect($command->name()->value)->toBe('note.save')
        ->and($command->version)->toBe(1)
        ->and($query->name()->value)->toBe('note.find_title')
        ->and($query->version)->toBe(1)
        ->and($write->handles)->toBe(SaveNote::class)
        ->and($write->surfaces)->toBe([Surface::Rest, Surface::Mcp])
        ->and($write->exposes(Surface::Cli))->toBeFalse()
        ->and($read->handles)->toBe(FindNoteTitle::class)
        ->and($read->surfaces)->toBe([Surface::Rest, Surface::Inertia]);
});
```

## The result of a write

A write ends in a `Cbox\Cms\Contracts\Results\WriteResult`, which carries the call's `Receipt` and what belongs to its outcome (PRD 6.1):

| Outcome | What the result carries |
|---|---|
| `rejected` | at least one `CatalogError`: a code from the error catalog, the `FieldPath` of the input it is about or null, and the cause in plain language. Nothing was committed. |
| `committed` | the receipt with the changeset |
| `committed_wait_timeout` | the receipt with the changeset; the wait level was not reached in time |
| `dry_run` | a `DryRunReport`: the plan the command would have committed, its `BlastRadius`, its diff and the placements it makes visible |

A dry run's `BlastRadius` counts the mutations of the plan and the distinct aggregates they change, by kind: `of('entry')`, `of('variant')` and `total()`. Its diff is one `AggregateChange` per aggregate the plan changes, sorted by aggregate key, with the version it was read at (`before`, null when the write creates it), the version the commit would give it (`after`) and the number of mutations that change it. Its `visible` lists the placements the plan makes visible, sorted by placement and locale, when the action `ReportsVisibility`, and none otherwise.

A `FieldPath` is a list of names and indexes, written `blocks[2].text` or `fields.ext.app.tax_code`. Each surface translates the result for its transport. No code serialises a result to JSON by hand: the receipt's JSON form is on [Receipt JSON](receipt-json.md), and a rejection's catalog errors become the field errors of [problem details](problem-details.md), each written by its generated codec.

## The CLI surface

A write action whose `#[Action]` lists `Surface::Cli` runs from the command line with one generic command, `cms:run <name> <version> <document>`, such as `php artisan cms:run note.save 1 '{"title":"A"}' --idempotency-key=note-1`. The names and versions it accepts are the write actions the registry that `cms:build` compiles exposes on the CLI; any other exits 64 and lists the ones it exposes. `<document>` is the command's JSON document, read by the command's generated codec at the actor's classification access, as REST and the Inertia profile read it.

| Option | Envelope field | What it does |
|---|---|---|
| `--idempotency-key=` | `idempotency_key` | required; a repeat with the same key and document gives the same receipt, another document with the same key is `idempotency_conflict` |
| `--dry-run` | `dry_run` | computes the plan and the receipt and commits nothing |
| `--wait-level=` | `wait_level` | `commit` (the default), `origin`, `edge`, `verified` or `propagated` |
| `--json` | | prints the receipt, or the problem details of a rejection, as one line of JSON |

The options are read through the envelope's generated codec, so a value it refuses is `json_invalid` at `envelope.<field>`, such as `envelope.wait_level`. The actor is never an argument or an option: it is the actor of the process's configured service credential, `cbox-cms.cli.credential` (see [configuration](../developers/configuration.md#cli-surface)), verified like a Bearer token, and a run without one is rejected with `unauthorized`. The on-behalf-of chain is the credential's.

The exit code comes from the [error catalog](../reference/errors.md): 0 for `committed` and `committed_wait_timeout` (the change is committed; do not run it again), the exit code of `dry_run` (0) for a dry run, and for a rejection the exit code of its first error, such as 65 for `validation_failed`, `json_invalid` and `version_conflict` and 77 for `unauthorized` and a refused credential. With `--json`, standard output holds the receipt ([receipt JSON](receipt-json.md)) of a call that was not rejected and the [problem details](problem-details.md) of one that was; paths of the command's errors are relative to `<document>`. Without it, the outcome is one sentence on standard output and each error of a rejection a line on standard error. An invalid `cbox-cms.cli.credential` exits 78, and an exposed command without a codec 70, with a message and no JSON.

## The MCP surface

Every action whose `#[Action]` lists `Surface::Mcp` is an MCP tool, for agents (PRD 22). The tools are compiled from the registry that `cms:build` writes: one per action, named after its command or query with a hyphen for each dot and `-v` and the version, such as `entry-create-v1` or `placement-set_window-v1`, so every MCP client takes the name. An application serves them on one Streamable HTTP endpoint by registering `Cbox\Cms\Mcp\Adapter\McpRoutes::register(app(\Laravel\Mcp\Server\Registrar::class))`, `POST /mcp` by default, outside the web middleware group, whose CSRF check would refuse an agent's POST. `laravel/mcp`, pinned to its minor 1.0, runs the protocol behind the module's adapter; nothing else in the package uses it.

A tool's input schema is made of the generated JSON forms it reads. A write's tool takes `command`, the command's document as the JSON Schema of its `CommandCodec` describes it, and `envelope`, the envelope of [envelope JSON](envelope-json.md), whose `idempotency_key` is required; `envelope.dry_run` computes the plan and the receipt without committing. A read's tool takes `query`, the query's document as the JSON Schema of its `QueryCodec` describes it. Each embedded schema keeps or gets an `$id` of its own, so its references resolve inside it. The tool's title and the start of its description are the schema's `title` and `description`, so a tool describes itself with the contract's own text (PRD 14.5). An action exposed on MCP whose command or query no codec reads cannot be described, so it is not offered; the tools name it with the reason.

The actor is never an argument: it is the agent of the request's Bearer credential, verified like every other credential, and a call through MCP is made only with a credential issued for an agent (`IssuerKind::Agent`), whose classification ceiling is at most `confidential`. Any other credential, or none, is `unauthorized`. The envelope's issuer kind is `agent`.

An agent sees a field only as its classification and its `agents` flag in the blueprint allow (PRD 2.31, 12.2), and the query pipeline enforces it for every read with an agent's credential, not only through MCP: a `public` or `internal` field unless it says `agents: false`, a `confidential` field only with `agents: true`, and a `personal` or `sensitive` field never. Inside a group agents see, a nested field that says `agents: false` is left out too. A read's result is written by the query's result codec at the agent's classification access.

The answer follows the [error catalog](../reference/errors.md)'s MCP column. A write that was not rejected is a tool result with its receipt ([receipt JSON](receipt-json.md)), `committed_wait_timeout` included, because the change is committed; an answered read is a tool result with its result. A rejection, or arguments the tool cannot read, is decided by its first error: a tool result with `isError` set for a code the agent can act on, such as `validation_failed`, `json_invalid` or `version_conflict`, and the JSON-RPC error -32603 for one the installation is at fault for, such as `registry_cache_missing`. Both carry the [problem details](problem-details.md) with every catalog code and path, as text and as structured content; the paths are below `command`, `envelope` or `query`, as the arguments hold the value. A call of a tool the surface does not offer is the JSON-RPC error -32602.

## The command pipeline

The kernel runs every write through one pipeline, in the core, and the action only resolves and plans (PRD 6.2, GUARDRAILS 2.1). The whole call runs in one transaction, which commits only when the call committed a changeset.

Before the phases comes idempotency. The kernel claims the envelope's idempotency key, in the scope of the actor and the command's name, with a hash of the command's name, version and input (PRD 6.1). It waits for another call that holds the same key at most the wait budget, `cbox-cms.idempotency.wait_budget_ms`. A fresh key runs the phases below. A key committed before with the same content returns that first call's receipt and runs nothing, so a retry after a timeout never commits twice; the receipt is built for the wait level the retry asks for, by the rule of the wait below, and a level not reached makes the retry `committed_wait_timeout`; a replay does not wait again. The same key with other content is `idempotency_conflict`, and a key another call still holds after the wait budget is `idempotency_in_flight`, which a client retries later with the same key and content. An internal issuer's key is the one its envelope derived from its unit of work. A dry run claims no key.

1. **Resolve.** The kernel reads the actor and every actor of its on-behalf-of chain as aggregates and rejects the call with `actor_not_active` when one is missing or not active (invariant 37). Then it calls `resolve()`, and checks the versions of a command that `ExpectsVersions`.
2. **Authorize.** The call's access context, the principal with its regions and classification access, is checked against the command and what was read; a refusal is `unauthorized`.
3. **Plan.** It calls `plan()`.
4. **Validate.** Every aggregate a mutation changes was read, every revision names a type of the installation, and the fields of every revision pass the type's generated validator (see [runtime validators](validation.md)). Any error rejects the call with `validation_failed`, followed by each field error with its path below `fields`.
5. **Dry run.** A call whose envelope asks for a dry run ends here with its `DryRunReport` and commits nothing.
6. **Commit.** The plan is written as one changeset in the call's transaction, with a version check of every aggregate read, the actors included, so a deactivation that commits while a command of the actor is under way fails that command with `version_conflict`. The changeset completes the idempotency key in the same transaction, so the key and the changeset commit together. A write that no partition covers is `partition_missing`, and nothing of the call is kept. A rejected call leaves the key fresh, and a retry runs again.
7. **Wait.** Once the transaction has committed, the call waits for the wait level its envelope asks for (PRD 8.4), at most `cbox-cms.receipts.wait_budget_ms` of real time (see [configuration](../developers/configuration.md#wait-levels)). `commit` returns at once. `origin` returns when the kernel's invalidation subscriber has purged the server fragments of what the changeset changed and acknowledged the projection `origin` on its receipt (see [subscribers](subscribers.md)); a receipt that lists no `origin` has reached it at commit. `edge`, `verified` and `propagated` are reached when every projection the receipt lists has acknowledged, until the invalidation in full scale defines the projections of each. A level not reached within the budget makes the call `committed_wait_timeout`: the change is committed, with its receipt and the projection statuses as they were last read, but the wait ran out. Nothing after commit makes a call fail, and the wait holds no transaction or lock.

`resolve()` and `plan()` get the command and the aggregates and nothing else: no connection, envelope or access context. An action lives in an `Actions` namespace, where the architecture tests and PHPStan forbid the framework, the DB facade, connections and transactions, so it cannot write.

## The REST surface

`cms:build` compiles the REST surface from the registry (GUARDRAILS 2.1): one route per action whose `#[Action]` lists `Surface::Rest`, written to `bootstrap/cache/cms/rest.php`, and an OpenAPI 3.1 document of those routes, `bootstrap/cache/cms/openapi.json`. The path carries the version of the REST contract, `v1`, first (PRD 8.8):

| Action | Route | Request | Answer |
|---|---|---|---|
| write action | `POST /v1/commands/<name>/v<version>`, such as `POST /v1/commands/note.save/v1` | the command's JSON document as the body; the envelope in headers; a Bearer credential | 200 with the receipt when it committed or ran dry, 202 with the receipt when it committed but did not reach its wait level (`committed_wait_timeout`, which is not sent again as a new command) |
| query action | `GET /v1/queries/<name>/v<version>` | the query's JSON document in the query parameter `query`, an empty object when it is left out; a Bearer credential, or none for the anonymous principal | 200 with the result, without the fields above the caller's classification access |

A rejected call, and a request the surface cannot read, answer with problem details (RFC 9457, `application/problem+json`, see [problem details](problem-details.md)): the catalog code of the error that decided it, the HTTP status the [error catalog](errors.md) gives that code, and every error with the path of its field in the document the caller sent. No answer may be stored by a cache (`Cache-Control: no-store, private`).

The envelope of a write comes from the headers, read through the envelope's generated codec (see [envelope JSON](envelope-json.md)):

| Header | Envelope field | When it is left out |
|---|---|---|
| `Idempotency-Key` | the idempotency key, 1 to 255 visible ASCII characters | the command is refused with `idempotency_key_required`: every command through REST carries a key |
| `Cbox-Wait-Level` | the wait level: `commit`, `origin`, `edge`, `verified` or `propagated` | `commit` |
| `Cbox-Dry-Run` | `true` or `false` | `false` |
| `Cbox-Correlation-Id` | the correlation id, 1 to 128 visible ASCII characters | the surface makes one |

A header sent twice, or one that breaks its rule, is refused with `request_header_invalid`, or `idempotency_key_required` for the key, and nothing runs. The actor and the on-behalf-of chain come from the credential alone, and REST sends no provenance.

The surface reads each command and query with its codecs, so a command or query on REST needs them registered in the container, each with the JSON Schema of the document it reads, which `openapi.json` describes the route with:

- a command: a `Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCodec` (name, version, the `JsonCodec` of the command and its `Cbox\Cms\Contracts\Codecs\JsonSchema`), tagged `cbox-cms.command-codecs`;
- a query: a `Cbox\Cms\Core\Reads\Domain\Dto\QueryCodec` (name, version, the codec and schema of the query, and the codec and schema of its result), tagged `cbox-cms.query-codecs`. A query holds no classified content and is read at public classification access; the result is written at the classification access of the read's principal.

An action on REST whose command or query has no codec fails `cms:build` with `registry_surface_without_codec`, and nothing is written. Every JSON Schema gets an `$id` of its own in `openapi.json` when it has none, `urn:cbox-cms:<component>`, so its references to its own `$defs` resolve inside it.

An application registers the routes in its API routes with the registry the container reads from the cache, `Cbox\Cms\Http\Rest\RestRoutes::register($router, app(CompiledRegistry::class))`. A route needs no session, cookies or CSRF token. Its controllers hold no logic: `RestRequest` reads the request, the core's shared action for exposed writes or the query pipeline runs the call, and `RestResponse` translates the typed result.
