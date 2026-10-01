---
title: Authorization
weight: 45
description: "How the kernel decides who may run a command or a read: permissions on roles, grants on nodes, the nodes a command's aggregates name, and public reads."
---

# Authorization

<!-- extension-point: Cbox\Cms\Contracts\Pipeline\AuthorizedOn -->
<!-- extension-point: Cbox\Cms\Contracts\Pipeline\PublicQuery -->

Both pipelines authorize a call after they have set the actor's context (PRD 5.10, 6.2). The rules come from three tables: a role's permissions in `role_permissions`, which are command and query names, an actor's grants in `grants`, each a role on a node, allowed or denied, in every locale or a set of them, and the tree of `nodes`. A role reaches a node when its grant nearest above the node, or on it, allows: a deny beats the allow it inherits, and an allow below a deny reaches that subtree again. A grant that ended, such as one a deactivation ended, gives nothing.

**Commands.** An actor may run a command when, on every node the command acts on, one of its roles whose permissions name the command reaches the node. The command's aggregates name those nodes: the class `resolve()` returns implements `Cbox\Cms\Contracts\Pipeline\AuthorizedOn`, and `authorizationTargets()` returns a `Cbox\Cms\Contracts\Pipeline\AuthorizationTarget` per node, with the locale the command acts in there, or null when it acts in no one locale. Only the grants that hold in a target's locale count for it; a target without a locale is reached when a role reaches it in some locale. The kernel's commands name these nodes:

- `entry.create`: the home node the command names.
- `entry.revise`, `variant.release` and `entry.unpublish`: the entry's home, where its content lives.
- `placement.create`: the node the placement goes below, in each locale of its slugs. `placement.set_window`: the placement's node in the window's locale. So a regional editor may place a story from another desk without being allowed to change its text.
- `entry.publish`: the entry's home, where the release is decided, and the home placement's node in the command's locale, where the window is.

A command is refused as `unauthorized` when the principal is anonymous, when its aggregates do not implement `AuthorizedOn` or name no node (so a command whose entry was not found names nothing), when the actor does not reach a node, and when no role with the permission reaches it. `actor.deactivate` names no node and so does not run through the grants; it is exposed on no surface. Authorize hooks run after this decision and can only add refusals (see [hooks](hooks.md)).

**Reads.** A query that implements `Cbox\Cms\Contracts\Pipeline\PublicQuery` reads published content and runs for anyone, the anonymous principal included; `path.resolve` is one. Any other read runs for an actor one of whose roles has its name among its permissions and reaches at least one node, and never for the anonymous principal. The authorizer does not decide which rows a read reaches; row level security under the actor's context does, so the anonymous principal reads only what is public.

The kernel binds `Cbox\Cms\Core\Access\Adapter\GrantCommandAuthorizer` and `GrantQueryAuthorizer`, which read the grants inside the pipeline's transaction. The seeder and the delivery API run pipelines of their own with their own authorizers: the seeder only `seed.entries` as its configured service actor, the delivery API only `path.resolve`.

<!-- example-file: examples/Unit/Pipeline/MoveNoteAggregates.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Pipeline;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Contracts\Pipeline\AuthorizedOn;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * What a command that moves a note's slug in one locale read: the note at its version, or null when
 * it was not found, the note's home and the node the note is placed under. The permission is checked
 * on the note's home, where its content lives, and on the placement's node in the locale, where its
 * slug lives; nothing is named when the note was not found, so the kernel refuses the command.
 */
final readonly class MoveNoteAggregates implements AuthorizedOn
{
    public function __construct(
        public EntryId $note,
        public ?AggregateVersion $version,
        public NodeId $home,
        public NodeId $placedUnder,
        public Locale $locale,
    ) {}

    #[Override]
    public function versions(): ReadVersions
    {
        return new ReadVersions($this->version instanceof AggregateVersion ? ReadVersion::at($this->note, $this->version) : ReadVersion::absent($this->note));
    }

    #[Override]
    public function authorizationTargets(): array
    {
        if (! $this->version instanceof AggregateVersion) {
            return [];
        }

        return [new AuthorizationTarget($this->home), new AuthorizationTarget($this->placedUnder, $this->locale)];
    }
}
```

<!-- example-file: examples/Unit/Pipeline/FindPublishedNote.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Pipeline;

use Cbox\Cms\Contracts\Attributes\Query as QueryType;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\PublicQuery;

/**
 * Asks for a published note, version 1 of note.find_published. It is a public read: anyone may run
 * it, the anonymous principal included, and row level security keeps it to what is published.
 */
#[QueryType('note.find_published', version: 1)]
final readonly class FindPublishedNote implements PublicQuery
{
    public function __construct(
        public EntryId $note,
    ) {}
}
```

<!-- example: examples/Unit/Pipeline/AuthorizationTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Contracts\Pipeline\PublicQuery;
use Examples\Unit\Pipeline\FindNoteTitle;
use Examples\Unit\Pipeline\FindPublishedNote;
use Examples\Unit\Pipeline\MoveNoteAggregates;

// The aggregates name the nodes a command is authorized on, and a public read says so by its type.

it('names the note\'s home and the placement\'s node in its locale, and nothing for a note not found', function (): void {
    $note = EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000001');
    $home = NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000a1');
    $placed = NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000a2');

    $found = new MoveNoteAggregates($note, new AggregateVersion(3), $home, $placed, new Locale('da'));
    $missing = new MoveNoteAggregates($note, null, $home, $placed, new Locale('da'));

    expect($found->authorizationTargets())->toEqual([new AuthorizationTarget($home), new AuthorizationTarget($placed, new Locale('da'))])
        ->and($missing->authorizationTargets())->toBe([]);
});

it('marks a read of published content as public, and leaves other reads to the permissions', function (): void {
    $note = EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000001');

    expect(new FindPublishedNote($note))->toBeInstanceOf(PublicQuery::class)
        ->and(new FindNoteTitle($note))->not->toBeInstanceOf(PublicQuery::class);
});
```
