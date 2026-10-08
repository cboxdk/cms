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
use Cbox\Cms\Testkit\Postgres\IndependentConnections;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/*
 * node.create, node.archive and node.set_route through the real command pipeline on Postgres (PRD
 * 5.8, 5.9, 5.10, 6.4, invariant 18, gap report G30), on the structure of the testkit's structure
 * fixtures: the sites north and south, each with a section. A node is created below a parent with
 * the parent's path and its own label below it; archiving is refused while a placement below the
 * node is visible now or later and commits once the window is closed; a route another node holds is
 * refused; two commands that claim one route commit one after the other, and the second is
 * version_conflict; an agent's credential and an envelope that records an agent are refused a route
 * (invariant 18); and the app role reads no node and no route without an actor context.
 */

afterEach(function (): void {
    EntryWorld::cleanUp();
});

const NODE_COMMANDS_SECTION = '0192a0c0-0000-7000-8000-0000000005a1';

const NODE_COMMANDS_PAGE = '0192a0c0-0000-7000-8000-0000000005a2';

const NODE_COMMANDS_OTHER = '0192a0c0-0000-7000-8000-0000000005a3';

const NODE_COMMANDS_ENTRY = '0192a0c0-0000-7000-8000-0000000005e1';

const NODE_COMMANDS_PLACEMENT = '0192a0c0-0000-7000-8000-0000000005c1';

/**
 * @return list<string>
 */
function nodeCodes(WriteResult $result): array
{
    return array_map(static fn (CatalogError $error): string => $error->code->value, $result->errors);
}

/**
 * The node as "<parent> <kind> <path> <lifecycle> <version>", read as the superuser.
 */
function nodeRow(string $node): string
{
    return StorageTables::texts(
        StorageTables::superuser(),
        "select coalesce(parent_id::text, '-') || ' ' || kind || ' ' || path::text || ' ' || lifecycle || ' ' || version::text as value from nodes where id = ?::uuid",
        [$node],
    )[0] ?? 'none';
}

/**
 * Each route of the site in the locale as "<route> <node>", read as the superuser.
 *
 * @return list<string>
 */
function nodeRoutes(string $site, string $locale = 'da'): array
{
    return StorageTables::texts(
        StorageTables::superuser(),
        "select route || ' ' || node_id::text as value from node_routes where site_id = ?::uuid and locale = ? order by route",
        [$site, $locale],
    );
}

/**
 * The node events as "<type> <node> <version>", read as the superuser.
 *
 * @return list<string>
 */
function nodeEvents(): array
{
    return StorageTables::texts(
        StorageTables::superuser(),
        "select type || ' ' || aggregate_id::text || ' ' || aggregate_version::text as value from events where type like 'node.%' order by event_id",
        [],
    );
}

it('creates a node below a parent with the parent\'s path and its own label, and tells about it', function (): void {
    $structure = NodeWorld::seed();
    $world = new NodeWorld([$structure->north->root]);
    $section = NodeId::fromString(NODE_COMMANDS_SECTION);
    $page = NodeId::fromString(NODE_COMMANDS_PAGE);
    $label = static fn (NodeId $node): string => str_replace('-', '', $node->toString());

    $created = $world->createNode($section, $structure->northSection, 'create-section');
    $below = $world->createNode($page, $structure->northSection, 'create-page', NodeKind::Page);

    expect($created->outcome())->toBe(Outcome::Committed)
        ->and($below->outcome())->toBe(Outcome::Committed)
        ->and(nodeRow(NODE_COMMANDS_SECTION))->toBe(sprintf(
            '%s section %s active 1',
            $structure->northSection->id->toString(),
            $structure->northSection->path->value.'.'.$label($section),
        ))
        ->and(nodeRow(NODE_COMMANDS_PAGE))->toBe(sprintf(
            '%s page %s active 1',
            $structure->northSection->id->toString(),
            $structure->northSection->path->value.'.'.$label($page),
        ))
        ->and(nodeEvents())->toBe([
            'node.created '.NODE_COMMANDS_SECTION.' 1',
            'node.created '.NODE_COMMANDS_PAGE.' 1',
        ])
        ->and(StorageTables::superuser()->table('changesets')->where('command', 'node.create')->count())->toBe(2);
});

it('refuses to archive a node while a placement below it is visible, and archives it once the window is closed', function (): void {
    $structure = NodeWorld::seed();
    $world = new NodeWorld([$structure->north->root]);
    $section = NodeId::fromString(NODE_COMMANDS_SECTION);
    $entry = EntryId::fromString(NODE_COMMANDS_ENTRY);
    $placement = PlacementId::fromString(NODE_COMMANDS_PLACEMENT);

    $world->createNode($section, $structure->northSection, 'create-section');
    $child = NodeWorld::childOf($structure->northSection, $section);
    $world->createEntry($entry, $child, 'entry');
    $world->place($placement, $entry, $child, $structure->north, ['da' => 'harbour'], 'place');
    $world->setWindow($placement, 1, new TimeWindow(new DateTimeImmutable(EntryWorld::NOW)), 'live');

    $live = $world->archiveNode($section, 'archive-live');

    expect(nodeCodes($live))->toBe(['validation_failed'])
        ->and($live->errors[0]->message)->toContain(NODE_COMMANDS_PLACEMENT)
        ->and($live->errors[0]->path?->toString())->toBe('node')
        ->and(nodeRow(NODE_COMMANDS_SECTION))->toContain('active 1');

    $world->setWindow($placement, 2, null, 'hide');
    $archived = $world->archiveNode($section, 'archive-hidden');
    $below = $world->createNode(NodeId::fromString(NODE_COMMANDS_OTHER), $child, 'below-archived');

    expect($archived->outcome())->toBe(Outcome::Committed)
        ->and(nodeRow(NODE_COMMANDS_SECTION))->toContain('archived 2')
        ->and(nodeEvents())->toBe(['node.created '.NODE_COMMANDS_SECTION.' 1', 'node.archived '.NODE_COMMANDS_SECTION.' 2'])
        ->and(nodeCodes($below))->toBe(['validation_failed'])
        ->and($below->errors[0]->message)->toContain('is archived');
});

it('gives a node its route and refuses a route another node holds and a second route for the same node', function (): void {
    $structure = NodeWorld::seed();
    $world = new NodeWorld([$structure->north->root]);
    $section = NodeId::fromString(NODE_COMMANDS_SECTION);
    $page = NodeId::fromString(NODE_COMMANDS_PAGE);

    $world->createNode($section, $structure->north->root, 'create-section');
    $world->createNode($page, $structure->north->root, 'create-page', NodeKind::Page);
    $set = $world->setRoute($section, $structure->north, '/sport', 'route');
    $taken = $world->setRoute($page, $structure->north, '/nyheder', 'taken');
    $again = $world->setRoute($section, $structure->north, '/handbold', 'again', version: 2);

    expect($set->outcome())->toBe(Outcome::Committed)
        ->and(nodeRoutes($structure->north->id->toString()))->toBe([
            '/ '.$structure->north->root->id->toString(),
            '/nyheder '.$structure->northSection->id->toString(),
            '/sport '.$section->toString(),
        ])
        ->and(nodeRow(NODE_COMMANDS_SECTION))->toContain('active 2')
        ->and(nodeEvents())->toBe([
            'node.created '.NODE_COMMANDS_SECTION.' 1',
            'node.created '.NODE_COMMANDS_PAGE.' 1',
            'node.route_changed '.NODE_COMMANDS_SECTION.' 2',
        ])
        ->and(nodeCodes($taken))->toBe(['node_route_taken'])
        ->and($taken->errors[0]->message)->toContain($structure->northSection->id->toString())
        ->and(nodeCodes($again))->toBe(['validation_failed'])
        ->and($again->errors[0]->message)->toContain('/sport');
});

it('commits one of two commands that claim one route at the same time and rejects the other with version_conflict', function (): void {
    $structure = NodeWorld::seed();
    [$first, $second] = app(IndependentConnections::class)->open(2);
    $regions = [$structure->north->root];
    $one = new NodeWorld($regions, $first->getName(), seed: 1);
    $other = new NodeWorld($regions, $second->getName(), seed: 2);
    $section = NodeId::fromString(NODE_COMMANDS_SECTION);
    $page = NodeId::fromString(NODE_COMMANDS_PAGE);

    $one->createNode($section, $structure->north->root, 'one-section');
    $one->createNode($page, $structure->north->root, 'one-page');

    $winner = null;
    $one->meanwhile = static function () use ($other, $page, $structure, &$winner): void {
        $winner = $other->setRoute($page, $structure->north, '/sport', 'second');
    };
    $loser = $one->setRoute($section, $structure->north, '/sport', 'first');

    expect($winner?->outcome())->toBe(Outcome::Committed)
        ->and(nodeCodes($loser))->toBe(['version_conflict'])
        ->and($loser->errors[0]->message)->toContain('node_route:'.$structure->north->id->toString().':da:/sport')
        ->and(nodeRoutes($structure->north->id->toString()))->toContain('/sport '.$page->toString());
});

it('refuses a route to an agent\'s credential and to an envelope that records an agent (invariant 18)', function (): void {
    $structure = NodeWorld::seed();
    $world = new NodeWorld([$structure->north->root]);
    $section = NodeId::fromString(NODE_COMMANDS_SECTION);

    $world->createNode($section, $structure->north->root, 'create-section');
    $credential = $world->setRoute($section, $structure->north, '/sport', 'agent-credential', agentCredential: true);
    $envelope = $world->setRoute($section, $structure->north, '/sport', 'agent-envelope', agentEnvelope: true);
    $person = $world->setRoute($section, $structure->north, '/sport', 'person');

    expect(nodeCodes($credential))->toBe(['agent_visibility_forbidden'])
        ->and(nodeCodes($envelope))->toBe(['agent_visibility_forbidden'])
        ->and($person->outcome())->toBe(Outcome::Committed)
        ->and(nodeRoutes($structure->north->id->toString()))->toContain('/sport '.$section->toString());
});

it('lets the app role read no node and no route without an actor context, and write neither', function (): void {
    $structure = NodeWorld::seed();
    $app = DB::connection();

    expect(StorageTables::superuser()->table('nodes')->count())->toBeGreaterThan(0)
        ->and(StorageTables::superuser()->table('node_routes')->count())->toBeGreaterThan(0)
        ->and($app->table('nodes')->count())->toBe(0)
        ->and($app->table('node_routes')->count())->toBe(0)
        ->and(StorageTables::sqlState(static fn (): bool => $app->table('nodes')->insert(StorageTables::node(NODE_COMMANDS_OTHER, kind: 'storage'))))->toBe('42501')
        ->and(StorageTables::sqlState(static fn (): bool => $app->table('node_routes')->insert([
            'site_id' => $structure->north->id->toString(),
            'locale' => 'da',
            'route' => '/sport',
            'node_id' => $structure->northSection->id->toString(),
            'created_at' => StorageTables::CREATED_AT,
        ])))->toBe('42501');
});
