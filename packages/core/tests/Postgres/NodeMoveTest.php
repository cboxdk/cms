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
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Tests\Entries\EntryWorld;
use Cbox\Cms\Core\Tests\Structure\NodeWorld;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Adapter\PostgresStructureFixtures;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Domain\Dto\StructureNode;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\IndependentConnections;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\DB;

/*
 * node.move through the real command pipeline on Postgres (PRD 5.8, 5.9, 5.10, 6.4, invariant 18),
 * on the structure of the testkit's structure fixtures: the sites north and south, each with a
 * section. A move puts the node below its new parent and rewrites every descendant's path in one
 * statement, while a route the node has stays; every refusal is validation_failed or unauthorized;
 * an actor granted on the new parent reaches the moved nodes, and one granted only on the old
 * parent no longer does; two crossing moves on IndependentConnections give one commit and one
 * version_conflict; an agent's move of a visible subtree across sites is
 * agent_visibility_forbidden; and a move costs the same statements with 20 and with 2000
 * descendants.
 */

afterEach(function (): void {
    EntryWorld::cleanUp();
});

const NODE_MOVE_A = '0192a0c0-0000-7000-8000-0000000006a1';

const NODE_MOVE_B = '0192a0c0-0000-7000-8000-0000000006a2';

const NODE_MOVE_C = '0192a0c0-0000-7000-8000-0000000006a3';

const NODE_MOVE_D = '0192a0c0-0000-7000-8000-0000000006a4';

const NODE_MOVE_E = '0192a0c0-0000-7000-8000-0000000006a5';

const NODE_MOVE_ENTRY = '0192a0c0-0000-7000-8000-0000000006e1';

const NODE_MOVE_PLACEMENT = '0192a0c0-0000-7000-8000-0000000006c1';

/**
 * @return list<string>
 */
function moveCodes(WriteResult $result): array
{
    return array_map(static fn (CatalogError $error): string => $error->code->value, $result->errors);
}

/**
 * The node as "<parent> <path> <version>", read as the superuser.
 */
function movedRow(NodeId $node): string
{
    return StorageTables::texts(
        StorageTables::superuser(),
        "select coalesce(parent_id::text, '-') || ' ' || path::text || ' ' || version::text as value from nodes where id = ?::uuid",
        [$node->toString()],
    )[0] ?? 'none';
}

/**
 * The node.moved events as "<node> <version>", read as the superuser.
 *
 * @return list<string>
 */
function movedEvents(): array
{
    return StorageTables::texts(
        StorageTables::superuser(),
        "select aggregate_id::text || ' ' || aggregate_version::text as value from events where type = 'node.moved' order by event_id",
    );
}

function moveNode(string $id): NodeId
{
    return NodeId::fromString($id);
}

/**
 * A node of the subtree, as the structure fixtures would give it.
 */
function moveChild(StructureNode $parent, string $id): StructureNode
{
    return NodeWorld::childOf($parent, moveNode($id));
}

/**
 * $count section nodes below the parent, written as the superuser with ids from $from up.
 */
function moveDescendants(StructureNode $parent, int $count, int $from): void
{
    $rows = [];

    for ($n = 0; $n < $count; $n++) {
        $rows[] = StorageTables::node(sprintf('0192a0c0-0000-7000-8000-%012d', $from + $n), $parent->id->toString(), $parent->path->value);
    }

    foreach (array_chunk($rows, 500) as $chunk) {
        StorageTables::superuser()->table('nodes')->insert($chunk);
    }
}

it('moves a node with its subtree under a new parent, rewrites every descendant\'s path, keeps its route and tells about it', function (): void {
    $structure = NodeWorld::seed();
    $world = new NodeWorld([$structure->north->root]);
    $a = moveNode(NODE_MOVE_A);
    $b = moveNode(NODE_MOVE_B);
    $c = moveNode(NODE_MOVE_C);
    $d = moveNode(NODE_MOVE_D);

    $world->createNode($a, $structure->north->root, 'create-a');
    $world->createNode($b, moveChild($structure->north->root, NODE_MOVE_A), 'create-b', NodeKind::Page);
    $world->createNode($c, moveChild(moveChild($structure->north->root, NODE_MOVE_A), NODE_MOVE_B), 'create-c', NodeKind::Page);
    $world->createNode($d, $structure->north->root, 'create-d');
    $world->setRoute($a, $structure->north, '/sport', 'route-a');
    $newParent = moveChild($structure->north->root, NODE_MOVE_D);

    $moved = $world->moveNode($a, $newParent, 'move-a', version: 2);
    $label = static fn (string $id): string => StorageTables::label($id);

    expect($moved->outcome())->toBe(Outcome::Committed)
        ->and(movedRow($a))->toBe(sprintf('%s %s.%s 3', NODE_MOVE_D, $newParent->path->value, $label(NODE_MOVE_A)))
        ->and(movedRow($b))->toBe(sprintf('%s %s.%s.%s 2', NODE_MOVE_A, $newParent->path->value, $label(NODE_MOVE_A), $label(NODE_MOVE_B)))
        ->and(movedRow($c))->toBe(sprintf('%s %s.%s.%s.%s 2', NODE_MOVE_B, $newParent->path->value, $label(NODE_MOVE_A), $label(NODE_MOVE_B), $label(NODE_MOVE_C)))
        ->and(movedRow($d))->toBe(sprintf('%s %s 1', $structure->north->root->id->toString(), $newParent->path->value))
        ->and(movedEvents())->toBe([NODE_MOVE_A.' 3'])
        ->and(StorageTables::texts(StorageTables::superuser(), "select route || ' ' || node_id::text as value from node_routes where route = '/sport'"))->toBe(['/sport '.NODE_MOVE_A])
        ->and(StorageTables::superuser()->table('changesets')->where('command', 'node.move')->count())->toBe(1);
});

it('refuses every move the tree rules out with validation_failed, and one outside the actor\'s regions with unauthorized', function (): void {
    $structure = NodeWorld::seed();
    $world = new NodeWorld([$structure->north->root]);
    $clock = new FakeClock(new DateTimeImmutable(EntryWorld::NOW));
    $fixtures = new PostgresStructureFixtures(app(ConnectionResolverInterface::class), $clock, new FakeIdGenerator(seed: 950, clock: $clock));
    $mount = $fixtures->mount($structure->north->root, $structure->northSection);
    $a = moveNode(NODE_MOVE_A);
    $b = moveNode(NODE_MOVE_B);
    $e = moveNode(NODE_MOVE_E);
    $aNode = moveChild($structure->north->root, NODE_MOVE_A);
    $bNode = moveChild($aNode, NODE_MOVE_B);

    $world->createNode($a, $structure->north->root, 'create-a');
    $world->createNode($b, $aNode, 'create-b');
    $world->createNode($e, $structure->north->root, 'create-e');
    $world->archiveNode($e, 'archive-e');
    $world->setRoute($a, $structure->north, '/sport', 'route-a');

    $refusals = [
        'below itself' => $world->moveNode($a, $aNode, 'self', version: 2),
        'below a descendant' => $world->moveNode($a, $bNode, 'descendant', version: 2),
        'below a mount' => $world->moveNode($a, $mount, 'mount', version: 2),
        'below an archived node' => $world->moveNode($a, moveChild($structure->north->root, NODE_MOVE_E), 'archived', version: 2),
        'a site root' => $world->moveNode($structure->north->root->id, $aNode, 'root'),
        'across sites with a route' => new NodeWorld([$structure->north->root, $structure->south->root], seed: 2)->moveNode($a, $structure->southSection, 'routed', version: 2),
        'a node outside the regions' => $world->moveNode($structure->southSection->id, $aNode, 'far-node'),
        'a parent outside the regions' => $world->moveNode($b, $structure->south->root, 'far-parent'),
    ];

    expect(array_map(moveCodes(...), $refusals))->toBe([
        'below itself' => ['validation_failed'],
        'below a descendant' => ['validation_failed'],
        'below a mount' => ['validation_failed'],
        'below an archived node' => ['validation_failed'],
        'a site root' => ['validation_failed'],
        'across sites with a route' => ['validation_failed'],
        'a node outside the regions' => ['unauthorized'],
        'a parent outside the regions' => ['unauthorized'],
    ])
        ->and($refusals['across sites with a route']->errors[0]->message)->toContain(NODE_MOVE_A)
        ->and(movedRow($a))->toContain($structure->north->root->id->toString().' ')
        ->and(movedEvents())->toBe([]);
});

it('lets an actor granted on the new parent reach the moved nodes, and one granted only on the old parent no longer', function (): void {
    $structure = NodeWorld::seed();
    $world = new NodeWorld([$structure->north->root]);
    $old = moveChild($structure->north->root, NODE_MOVE_D);
    $new = moveChild($structure->north->root, NODE_MOVE_E);
    $a = moveNode(NODE_MOVE_A);
    $b = moveNode(NODE_MOVE_B);

    $world->createNode($old->id, $structure->north->root, 'create-old');
    $world->createNode($new->id, $structure->north->root, 'create-new');
    $world->createNode($a, $old, 'create-a');
    $world->createNode($b, moveChild($old, NODE_MOVE_A), 'create-b', NodeKind::Page);

    $onOld = new NodeWorld([$old], seed: 2);
    $onNew = new NodeWorld([$new], seed: 3);

    $before = $onOld->setLabel($a, 'Before', 'before-old');
    $moved = $world->moveNode($a, $new, 'move-a', version: 2);
    $afterOld = $onOld->setLabel($a, 'After', 'after-old', version: 3);
    $afterOldChild = $onOld->setLabel($b, 'After', 'after-old-child', version: 2);
    $afterNew = $onNew->setLabel($a, 'After', 'after-new', version: 3);
    $afterNewChild = $onNew->setLabel($b, 'After', 'after-new-child', version: 2);

    expect($before->outcome())->toBe(Outcome::Committed)
        ->and($moved->outcome())->toBe(Outcome::Committed)
        ->and(moveCodes($afterOld))->toBe(['unauthorized'])
        ->and(moveCodes($afterOldChild))->toBe(['unauthorized'])
        ->and($afterNew->outcome())->toBe(Outcome::Committed)
        ->and($afterNewChild->outcome())->toBe(Outcome::Committed);
});

it('commits one of two moves that would make a cycle together and rejects the other with version_conflict', function (): void {
    $structure = NodeWorld::seed();
    [$first, $second] = app(IndependentConnections::class)->open(2);
    $regions = [$structure->north->root];
    $one = new NodeWorld($regions, $first->getName(), seed: 1);
    $other = new NodeWorld($regions, $second->getName(), seed: 2);
    $a = moveNode(NODE_MOVE_A);
    $b = moveNode(NODE_MOVE_B);
    $aNode = moveChild($structure->north->root, NODE_MOVE_A);
    $bNode = moveChild($structure->north->root, NODE_MOVE_B);

    $one->createNode($a, $structure->north->root, 'one-a');
    $one->createNode($b, $structure->north->root, 'one-b');

    $winner = null;
    $one->meanwhile = static function () use ($other, $b, $aNode, &$winner): void {
        $winner = $other->moveNode($b, $aNode, 'b-under-a');
    };
    $loser = $one->moveNode($a, $bNode, 'a-under-b');

    expect($winner?->outcome())->toBe(Outcome::Committed)
        ->and(moveCodes($loser))->toBe(['version_conflict'])
        ->and($loser->errors[0]->message)->toContain('node:'.NODE_MOVE_B)
        ->and(movedRow($b))->toBe(sprintf('%s %s.%s 2', NODE_MOVE_A, $aNode->path->value, StorageTables::label(NODE_MOVE_B)))
        ->and(movedRow($a))->toBe(sprintf('%s %s 1', $structure->north->root->id->toString(), $aNode->path->value))
        ->and(movedEvents())->toBe([NODE_MOVE_B.' 2']);
});

it('refuses an agent a move that takes a visible placement to another site, and lets a person make it (invariant 18)', function (): void {
    $structure = NodeWorld::seed();
    $world = new NodeWorld([$structure->north->root, $structure->south->root]);
    $a = moveNode(NODE_MOVE_A);
    $aNode = moveChild($structure->north->root, NODE_MOVE_A);
    $entry = EntryId::fromString(NODE_MOVE_ENTRY);
    $placement = PlacementId::fromString(NODE_MOVE_PLACEMENT);

    $world->createNode($a, $structure->north->root, 'create-a');
    $world->createEntry($entry, $aNode, 'entry');
    $world->place($placement, $entry, $aNode, $structure->north, ['da' => 'harbour'], 'place');
    $world->setWindow($placement, 1, new TimeWindow(new DateTimeImmutable(EntryWorld::NOW)), 'live');

    $credential = $world->moveNode($a, $structure->southSection, 'agent-credential', agentCredential: true);
    $envelope = $world->moveNode($a, $structure->southSection, 'agent-envelope', agentEnvelope: true);
    $root = $world->moveNode($a, $structure->south->root, 'agent-root', agentCredential: true);
    $person = $world->moveNode($a, $structure->southSection, 'person');

    expect(moveCodes($credential))->toBe(['agent_visibility_forbidden'])
        ->and(moveCodes($envelope))->toBe(['agent_visibility_forbidden'])
        ->and(moveCodes($root))->toBe(['agent_visibility_forbidden'])
        ->and($person->outcome())->toBe(Outcome::Committed)
        ->and(movedRow($a))->toBe(sprintf('%s %s.%s 2', $structure->southSection->id->toString(), $structure->southSection->path->value, StorageTables::label(NODE_MOVE_A)));
});

it('costs the same statements to move a subtree of 20 and of 2000 descendants', function (): void {
    $structure = NodeWorld::seed();
    $world = new NodeWorld([$structure->north->root]);
    $target = moveChild($structure->north->root, NODE_MOVE_D);
    $world->createNode($target->id, $structure->north->root, 'create-target');
    $counts = [];

    foreach ([20 => NODE_MOVE_A, 2000 => NODE_MOVE_B] as $descendants => $id) {
        $node = moveNode($id);
        $world->createNode($node, $structure->north->root, 'create-'.$descendants);
        moveDescendants(moveChild($structure->north->root, $id), $descendants, 10_000 * ($descendants === 20 ? 1 : 2));

        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $moved = $world->moveNode($node, $target, 'move-'.$descendants);
        $statements = count($connection->getQueryLog());
        $connection->disableQueryLog();
        $connection->flushQueryLog();

        $counts[$descendants] = [$moved->outcome(), $statements];
    }

    $below = static fn (string $id): int => StorageTables::superuser()->table('nodes')->whereRaw('path <@ ?::ltree', [$target->path->value.'.'.StorageTables::label($id)])->count();
    $left = static fn (string $id): int => StorageTables::superuser()->table('nodes')->whereRaw('path <@ ?::ltree', [$structure->north->root->path->value.'.'.StorageTables::label($id)])->count();

    expect($counts[20][0])->toBe(Outcome::Committed)
        ->and($counts[2000][0])->toBe(Outcome::Committed)
        ->and($counts[2000][1])->toBe($counts[20][1])
        ->and($below(NODE_MOVE_A))->toBe(21)
        ->and($below(NODE_MOVE_B))->toBe(2001)
        ->and($left(NODE_MOVE_A))->toBe(0)
        ->and($left(NODE_MOVE_B))->toBe(0)
        ->and(StorageTables::superuser()->table('nodes')->where('parent_id', NODE_MOVE_B)->where('version', 2)->count())->toBe(2000);
});

it('lets the app role read no node without an actor context after a move, as before', function (): void {
    $structure = NodeWorld::seed();
    $world = new NodeWorld([$structure->north->root]);
    $a = moveNode(NODE_MOVE_A);
    $d = moveChild($structure->north->root, NODE_MOVE_D);

    $world->createNode($a, $structure->north->root, 'create-a');
    $world->createNode($d->id, $structure->north->root, 'create-d');
    $world->moveNode($a, $d, 'move-a');

    expect(StorageTables::superuser()->table('nodes')->count())->toBeGreaterThan(0)
        ->and(DB::connection()->table('nodes')->count())->toBe(0)
        ->and(DB::connection()->table('nodes')->where('id', NODE_MOVE_A)->update(['version' => 9]))->toBe(0)
        ->and(StorageTables::superuser()->table('nodes')->where('id', NODE_MOVE_A)->value('version'))->toBe(2);
});
