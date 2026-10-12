<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Tests\Entries\EntryWorld;
use Cbox\Cms\Core\Tests\Structure\NodeWorld;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Domain\Dto\StructureNode;
use Cbox\Cms\Testkit\Postgres\IndependentConnections;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/*
 * mount.create and mount.set_override through the real command pipeline on Postgres (PRD 5.8,
 * 5.10, 6.4, invariant 18), on the structure of the testkit's structure fixtures: the sites north
 * and south, each with a section. A mount is a node of kind mount with its source, created with
 * the rights on its parent; every refusal is validation_failed or unauthorized; a mount.create that
 * races a node.move putting the source above the mount on IndependentConnections gives one commit
 * and one version_conflict; an exception is set and taken off as a row of mount_overrides under
 * forced row level security, with the mount one version up each time; an agent may hide but not
 * un-hide an entry the source shows, and an un-hide planned as not public rolls back when a window
 * opened on the entry meanwhile; and a mount is archived like any node, because it holds no
 * placement of its own.
 */

afterEach(function (): void {
    EntryWorld::cleanUp();
});

const MOUNT_A = '0192a0c0-0000-7000-8000-0000000007a1';

const MOUNT_B = '0192a0c0-0000-7000-8000-0000000007a2';

const MOUNT_M = '0192a0c0-0000-7000-8000-0000000007a3';

const MOUNT_N = '0192a0c0-0000-7000-8000-0000000007a4';

const MOUNT_E = '0192a0c0-0000-7000-8000-0000000007a5';

const MOUNT_GONE = '0192a0c0-0000-7000-8000-0000000007a6';

const MOUNT_ENTRY = '0192a0c0-0000-7000-8000-0000000007e1';

const MOUNT_NO_ENTRY = '0192a0c0-0000-7000-8000-0000000007e2';

const MOUNT_PLACEMENT = '0192a0c0-0000-7000-8000-0000000007c1';

/**
 * @return list<string>
 */
function mountCodes(WriteResult $result): array
{
    return array_map(static fn (CatalogError $error): string => $error->code->value, $result->errors);
}

/**
 * The node as "<parent> <kind> <source> <path> <version>", read as the superuser.
 */
function mountRow(NodeId $node): string
{
    return StorageTables::texts(
        StorageTables::superuser(),
        "select coalesce(parent_id::text, '-') || ' ' || kind || ' ' || coalesce(mount_source_id::text, '-') || ' ' || path::text || ' ' || version::text as value from nodes where id = ?::uuid",
        [$node->toString()],
    )[0] ?? 'none';
}

/**
 * The overrides of the mount as "<entry> <hidden> <version>", read as the superuser.
 *
 * @return list<string>
 */
function mountOverrides(NodeId $mount): array
{
    return StorageTables::texts(
        StorageTables::superuser(),
        "select entry_id::text || ' ' || hidden::text || ' ' || version::text as value from mount_overrides where mount_node_id = ?::uuid order by entry_id",
        [$mount->toString()],
    );
}

/**
 * The events of the type as "<aggregate> <version>", read as the superuser.
 *
 * @return list<string>
 */
function mountEvents(string $type): array
{
    return StorageTables::texts(
        StorageTables::superuser(),
        "select aggregate_id::text || ' ' || aggregate_version::text as value from events where type = ? order by event_id",
        [$type],
    );
}

function mountNode(string $id): NodeId
{
    return NodeId::fromString($id);
}

function mountChild(StructureNode $parent, string $id): StructureNode
{
    return NodeWorld::childOf($parent, mountNode($id));
}

it('creates a mount below a node showing its source, on the parent\'s path, and tells about it', function (): void {
    $structure = NodeWorld::seed();
    $world = new NodeWorld([$structure->south->root]);
    $mount = mountNode(MOUNT_M);

    $created = $world->createMount($mount, $structure->south->root, $structure->northSection, 'create-mount');

    expect($created->outcome())->toBe(Outcome::Committed)
        ->and(mountRow($mount))->toBe(sprintf('%s mount %s %s.%s 1', $structure->south->root->id->toString(), $structure->northSection->id->toString(), $structure->south->root->path->value, StorageTables::label(MOUNT_M)))
        ->and(mountEvents('mount.created'))->toBe([MOUNT_M.' 1'])
        ->and(StorageTables::superuser()->table('changesets')->where('command', 'mount.create')->count())->toBe(1)
        ->and(StorageTables::superuser()->table('audit')->whereRaw('? = any (aggregates)', ['node:'.MOUNT_M])->count())->toBe(1);
});

it('refuses every mount the tree rules out with validation_failed, and a parent outside the actor\'s regions with unauthorized', function (): void {
    $structure = NodeWorld::seed();
    $world = new NodeWorld([$structure->north->root]);
    $a = mountNode(MOUNT_A);
    $b = mountNode(MOUNT_B);
    $e = mountNode(MOUNT_E);
    $aNode = mountChild($structure->north->root, MOUNT_A);
    $bNode = mountChild($aNode, MOUNT_B);
    $eNode = mountChild($structure->north->root, MOUNT_E);
    $first = mountNode(MOUNT_M);
    $firstMount = mountChild($structure->north->root, MOUNT_M);

    $world->createNode($a, $structure->north->root, 'create-a');
    $world->createNode($b, $aNode, 'create-b');
    $world->createNode($e, $structure->north->root, 'create-e');
    $world->archiveNode($e, 'archive-e');
    $world->createMount($first, $structure->north->root, $structure->northSection, 'create-first');
    $gone = new StructureNode(mountNode(MOUNT_GONE), $structure->north->root->path);

    $refusals = [
        'a parent that does not exist' => $world->createMount(mountNode(MOUNT_N), $gone, $aNode, 'parent-gone'),
        'an archived parent' => $world->createMount(mountNode(MOUNT_N), $eNode, $aNode, 'parent-archived'),
        'a mount as parent' => $world->createMount(mountNode(MOUNT_N), $firstMount, $aNode, 'parent-mount'),
        'a source that does not exist' => $world->createMount(mountNode(MOUNT_N), $aNode, $gone, 'source-gone'),
        'the mount itself as source' => $world->createMount(mountNode(MOUNT_N), $aNode, new StructureNode(mountNode(MOUNT_N), $aNode->path), 'source-self'),
        'a mount as source' => $world->createMount(mountNode(MOUNT_N), $aNode, $firstMount, 'source-mount'),
        'an archived source' => $world->createMount(mountNode(MOUNT_N), $aNode, $eNode, 'source-archived'),
        'the parent as source' => $world->createMount(mountNode(MOUNT_N), $bNode, $bNode, 'source-parent'),
        'an ancestor as source' => $world->createMount(mountNode(MOUNT_N), $bNode, $aNode, 'source-ancestor'),
        'a parent outside the regions' => $world->createMount(mountNode(MOUNT_N), $structure->southSection, $aNode, 'far-parent'),
    ];

    expect(array_map(mountCodes(...), $refusals))->toBe([
        'a parent that does not exist' => ['validation_failed'],
        'an archived parent' => ['validation_failed'],
        'a mount as parent' => ['validation_failed'],
        'a source that does not exist' => ['validation_failed'],
        'the mount itself as source' => ['validation_failed'],
        'a mount as source' => ['validation_failed'],
        'an archived source' => ['validation_failed'],
        'the parent as source' => ['validation_failed'],
        'an ancestor as source' => ['validation_failed'],
        'a parent outside the regions' => ['unauthorized'],
    ])
        ->and($refusals['an ancestor as source']->errors[0]->message)->toContain('below its own source')
        ->and(mountRow(mountNode(MOUNT_N)))->toBe('none')
        ->and(mountEvents('mount.created'))->toBe([MOUNT_M.' 1']);
});

it('commits one of a mount.create and a node.move that would put the source above the mount, and rejects the other with version_conflict', function (): void {
    $structure = NodeWorld::seed();
    [$first, $second] = app(IndependentConnections::class)->open(2);
    $regions = [$structure->north->root];
    $one = new NodeWorld($regions, $first->getName(), seed: 1);
    $other = new NodeWorld($regions, $second->getName(), seed: 2);
    $a = mountNode(MOUNT_A);
    $b = mountNode(MOUNT_B);
    $aNode = mountChild($structure->north->root, MOUNT_A);
    $bNode = mountChild($structure->north->root, MOUNT_B);

    $one->createNode($a, $structure->north->root, 'one-a');
    $one->createNode($b, $structure->north->root, 'one-b');

    $winner = null;
    $one->meanwhile = static function () use ($other, $b, $aNode, &$winner): void {
        $winner = $other->moveNode($b, $aNode, 'b-under-a');
    };
    $loser = $one->createMount(mountNode(MOUNT_M), $bNode, $aNode, 'mount-under-b');

    expect($winner?->outcome())->toBe(Outcome::Committed)
        ->and(mountCodes($loser))->toBe(['version_conflict'])
        ->and($loser->errors[0]->message)->toContain('node:'.MOUNT_B)
        ->and(mountRow(mountNode(MOUNT_M)))->toBe('none')
        ->and(mountRow($b))->toBe(sprintf('%s section - %s.%s 2', MOUNT_A, $aNode->path->value, StorageTables::label(MOUNT_B)));
});

it('sets and takes off an exception for an entry, one version of the mount each, under forced row level security on mount_overrides', function (): void {
    $structure = NodeWorld::seed();
    $world = new NodeWorld([$structure->north->root, $structure->south->root]);
    $mount = mountNode(MOUNT_M);
    $entry = EntryId::fromString(MOUNT_ENTRY);

    $world->createEntry($entry, $structure->northSection, 'entry');
    $world->createMount($mount, $structure->south->root, $structure->northSection, 'create-mount');

    $hidden = $world->setOverride($mount, $entry, true, 'hide');
    $again = $world->setOverride($mount, $entry, true, 'hide-again', version: 2);
    $noEntry = $world->setOverride($mount, EntryId::fromString(MOUNT_NO_ENTRY), true, 'no-entry', version: 2);
    $notMount = $world->setOverride($structure->northSection->id, $entry, true, 'not-mount');
    $rows = mountOverrides($mount);
    $shown = $world->setOverride($mount, $entry, false, 'show', version: 2);
    $notHidden = $world->setOverride($mount, $entry, false, 'show-again', version: 3);
    $rls = StorageTables::texts(StorageTables::superuser(), "select relrowsecurity::text || ' ' || relforcerowsecurity::text as value from pg_class where relname = 'mount_overrides'");

    expect($hidden->outcome())->toBe(Outcome::Committed)
        ->and($rows)->toBe([MOUNT_ENTRY.' true 2'])
        ->and(mountCodes($again))->toBe(['validation_failed'])
        ->and(mountCodes($noEntry))->toBe(['validation_failed'])
        ->and(mountCodes($notMount))->toBe(['validation_failed'])
        ->and($shown->outcome())->toBe(Outcome::Committed)
        ->and(mountOverrides($mount))->toBe([])
        ->and(mountCodes($notHidden))->toBe(['validation_failed'])
        ->and(mountRow($mount))->toContain(' 3')
        ->and(mountEvents('mount.override_set'))->toBe([MOUNT_M.' 2', MOUNT_M.' 3'])
        ->and($rls)->toBe(['true true'])
        ->and(DB::connection()->table('mount_overrides')->count())->toBe(0);
});

it('refuses an exception on a mount outside the actor\'s regions with unauthorized, decided on the mount\'s node', function (): void {
    $structure = NodeWorld::seed();
    $south = new NodeWorld([$structure->south->root]);
    $north = new NodeWorld([$structure->north->root], seed: 2);
    $mount = mountNode(MOUNT_M);
    $entry = EntryId::fromString(MOUNT_ENTRY);

    $north->createEntry($entry, $structure->northSection, 'entry');
    $south->createMount($mount, $structure->south->root, $structure->northSection, 'create-mount');

    $refused = $north->setOverride($mount, $entry, true, 'hide-far');
    $allowed = $south->setOverride($mount, $entry, true, 'hide-near');

    expect(mountCodes($refused))->toBe(['unauthorized'])
        ->and($allowed->outcome())->toBe(Outcome::Committed)
        ->and(mountOverrides($mount))->toBe([MOUNT_ENTRY.' true 2']);
});

it('lets an agent hide an entry and refuses it un-hiding one the source shows with agent_visibility_forbidden (invariant 18)', function (): void {
    $structure = NodeWorld::seed();
    $world = new NodeWorld([$structure->north->root, $structure->south->root]);
    $mount = mountNode(MOUNT_M);
    $entry = EntryId::fromString(MOUNT_ENTRY);
    $placement = PlacementId::fromString(MOUNT_PLACEMENT);

    $world->createEntry($entry, $structure->northSection, 'entry');
    $world->place($placement, $entry, $structure->northSection, $structure->north, ['da' => 'harbour'], 'place');
    $world->setWindow($placement, 1, new TimeWindow(new DateTimeImmutable(EntryWorld::NOW)), 'live');
    $world->createMount($mount, $structure->south->root, $structure->northSection, 'create-mount');

    $hidden = $world->setOverride($mount, $entry, true, 'agent-hide', agentCredential: true);
    $credential = $world->setOverride($mount, $entry, false, 'agent-credential', version: 2, agentCredential: true);
    $envelope = $world->setOverride($mount, $entry, false, 'agent-envelope', version: 2, agentEnvelope: true);
    $person = $world->setOverride($mount, $entry, false, 'person', version: 2);

    expect($hidden->outcome())->toBe(Outcome::Committed)
        ->and(mountCodes($credential))->toBe(['agent_visibility_forbidden'])
        ->and(mountCodes($envelope))->toBe(['agent_visibility_forbidden'])
        ->and($person->outcome())->toBe(Outcome::Committed)
        ->and(mountOverrides($mount))->toBe([]);
});

it('rolls back un-hiding planned as not public when a window opened on the entry meanwhile, so an agent never makes content public (invariant 18)', function (): void {
    $structure = NodeWorld::seed();
    [$first, $second] = app(IndependentConnections::class)->open(2);
    $regions = [$structure->north->root, $structure->south->root];
    $one = new NodeWorld($regions, $first->getName(), seed: 1);
    $other = new NodeWorld($regions, $second->getName(), seed: 2);
    $mount = mountNode(MOUNT_M);
    $entry = EntryId::fromString(MOUNT_ENTRY);
    $placement = PlacementId::fromString(MOUNT_PLACEMENT);

    $one->createEntry($entry, $structure->northSection, 'entry');
    $one->place($placement, $entry, $structure->northSection, $structure->north, ['da' => 'harbour'], 'place');
    $one->createMount($mount, $structure->south->root, $structure->northSection, 'create-mount');
    $one->setOverride($mount, $entry, true, 'hide');

    $opened = null;
    $one->meanwhile = static function () use ($other, $placement, &$opened): void {
        $opened = $other->setWindow($placement, 1, new TimeWindow(new DateTimeImmutable(EntryWorld::NOW)), 'live-meanwhile');
    };

    expect(static fn (): WriteResult => $one->setOverride($mount, $entry, false, 'agent-show', version: 2, agentCredential: true))
        ->toThrow(LogicException::class, 'became visible')
        ->and($opened?->outcome())->toBe(Outcome::Committed)
        ->and(mountOverrides($mount))->toBe([MOUNT_ENTRY.' true 2'])
        ->and(mountRow($mount))->toContain(' 2')
        ->and(mountEvents('mount.override_set'))->toBe([MOUNT_M.' 2']);
});

it('archives a mount, which holds no placement of its own, and refuses archiving its source while a placement below it is visible', function (): void {
    $structure = NodeWorld::seed();
    $world = new NodeWorld([$structure->north->root, $structure->south->root]);
    $mount = mountNode(MOUNT_M);
    $entry = EntryId::fromString(MOUNT_ENTRY);
    $placement = PlacementId::fromString(MOUNT_PLACEMENT);

    $world->createEntry($entry, $structure->northSection, 'entry');
    $world->place($placement, $entry, $structure->northSection, $structure->north, ['da' => 'harbour'], 'place');
    $world->setWindow($placement, 1, new TimeWindow(new DateTimeImmutable(EntryWorld::NOW)), 'live');
    $world->createMount($mount, $structure->south->root, $structure->northSection, 'create-mount');

    $source = $world->archiveNode($structure->northSection->id, 'archive-source');
    $archived = $world->archiveNode($mount, 'archive-mount');

    expect(mountCodes($source))->toBe(['validation_failed'])
        ->and($source->errors[0]->message)->toContain(MOUNT_PLACEMENT)
        ->and($archived->outcome())->toBe(Outcome::Committed)
        ->and(mountRow($mount))->toContain(' 2')
        ->and(StorageTables::superuser()->table('nodes')->where('id', MOUNT_M)->value('lifecycle'))->toBe('archived');
});

it('lets the app role read no override without an actor context', function (): void {
    $structure = NodeWorld::seed();
    $world = new NodeWorld([$structure->north->root, $structure->south->root]);
    $mount = mountNode(MOUNT_M);
    $entry = EntryId::fromString(MOUNT_ENTRY);

    $world->createEntry($entry, $structure->northSection, 'entry');
    $world->createMount($mount, $structure->south->root, $structure->northSection, 'create-mount');
    $world->setOverride($mount, $entry, true, 'hide');

    expect(StorageTables::superuser()->table('mount_overrides')->count())->toBe(1)
        ->and(DB::connection()->table('mount_overrides')->count())->toBe(0)
        ->and(DB::connection()->table('mount_overrides')->where('mount_node_id', MOUNT_M)->delete())->toBe(0)
        ->and(StorageTables::superuser()->table('mount_overrides')->count())->toBe(1);
});
