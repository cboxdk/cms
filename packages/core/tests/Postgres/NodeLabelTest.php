<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Access\Infrastructure\ActorContext;
use Cbox\Cms\Core\Tests\Entries\EntryWorld;
use Cbox\Cms\Core\Tests\Structure\NodeWorld;
use DateInterval;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\DB;

/*
 * node.set_label through the real command pipeline on Postgres (PRD 5.8, 5.10, 6.4), on the
 * structure of the testkit's structure fixtures: a label is set per language in `node_labels`,
 * replaced by the next in the same language, moves the node a version up and emits
 * node.label_changed; an archived node, a node outside the actor's regions and a stale version
 * are refused; `node_labels` has forced row level security, so the app role reads no label and
 * writes none without an actor context, while any context, the anonymous one included, reads
 * them.
 */

afterEach(function (): void {
    EntryWorld::cleanUp();
});

const NODE_LABEL_A = '0192a0c0-0000-7000-8000-0000000007a1';

const NODE_LABEL_E = '0192a0c0-0000-7000-8000-0000000007a5';

/**
 * @return list<string>
 */
function labelCodes(WriteResult $result): array
{
    return array_map(static fn (CatalogError $error): string => $error->code->value, $result->errors);
}

/**
 * The labels of the node as "<locale> <label> <updated>", read as the superuser, where updated says
 * whether the row was replaced after it was created.
 *
 * @return list<string>
 */
function nodeLabels(string $node): array
{
    return StorageTables::texts(
        StorageTables::superuser(),
        "select locale || ' ' || label || ' ' || (updated_at > created_at)::text as value from node_labels where node_id = ?::uuid order by locale",
        [$node],
    );
}

/**
 * The node.label_changed events as "<node> <version>", read as the superuser.
 *
 * @return list<string>
 */
function labelEvents(): array
{
    return StorageTables::texts(
        StorageTables::superuser(),
        "select aggregate_id::text || ' ' || aggregate_version::text as value from events where type = 'node.label_changed' order by event_id",
    );
}

it('sets a label per language, replaces one, moves the node a version up and tells about it', function (): void {
    $structure = NodeWorld::seed();
    $world = new NodeWorld([$structure->north->root]);
    $a = NodeId::fromString(NODE_LABEL_A);
    $world->createNode($a, $structure->north->root, 'create-a');

    $danish = $world->setLabel($a, 'Sport', 'label-da');
    $english = $world->setLabel($a, 'Sports', 'label-en', version: 2, locale: 'en');
    $world->clock->advance(new DateInterval('PT1M'));
    $replaced = $world->setLabel($a, 'Sport og fritid', 'label-da-again', version: 3);

    expect($danish->outcome())->toBe(Outcome::Committed)
        ->and($english->outcome())->toBe(Outcome::Committed)
        ->and($replaced->outcome())->toBe(Outcome::Committed)
        ->and(nodeLabels(NODE_LABEL_A))->toBe(['da Sport og fritid true', 'en Sports false'])
        ->and(StorageTables::superuser()->table('nodes')->where('id', NODE_LABEL_A)->value('version'))->toBe(4)
        ->and(labelEvents())->toBe([NODE_LABEL_A.' 2', NODE_LABEL_A.' 3', NODE_LABEL_A.' 4'])
        ->and(StorageTables::texts(StorageTables::superuser(), "select data->'locale'->>'identifier' as value from events where type = 'node.label_changed' order by event_id"))->toBe(['da', 'en', 'da'])
        ->and(StorageTables::superuser()->table('events')->where('type', 'node.label_changed')->whereRaw("data::text like '%Sport%'")->count())->toBe(0);
});

it('refuses an archived node, a node outside the actor\'s regions and a stale version', function (): void {
    $structure = NodeWorld::seed();
    $world = new NodeWorld([$structure->north->root]);
    $e = NodeId::fromString(NODE_LABEL_E);
    $world->createNode($e, $structure->north->root, 'create-e');
    $world->archiveNode($e, 'archive-e');

    $archived = $world->setLabel($e, 'Gone', 'label-archived', version: 2);
    $far = $world->setLabel($structure->southSection->id, 'Far', 'label-far');
    $stale = $world->setLabel($structure->northSection->id, 'Stale', 'label-stale', version: 7);

    expect(labelCodes($archived))->toBe(['validation_failed'])
        ->and(labelCodes($far))->toBe(['unauthorized'])
        ->and(labelCodes($stale))->toBe(['version_conflict'])
        ->and(StorageTables::superuser()->table('node_labels')->count())->toBe(0);
});

it('forces row level security on node_labels, so the app role reads and writes none without an actor context, while any context reads them', function (): void {
    $structure = NodeWorld::seed();
    $world = new NodeWorld([$structure->north->root]);
    $world->setLabel($structure->northSection->id, 'Nyheder', 'label-news');
    $app = DB::connection();

    expect(StorageTables::texts(DB::connection('pgsql_owner'), "select relrowsecurity::text || ' ' || relforcerowsecurity::text as value from pg_class where oid = 'node_labels'::regclass"))->toBe(['true true'])
        ->and(StorageTables::superuser()->table('node_labels')->count())->toBe(1)
        ->and($app->table('node_labels')->count())->toBe(0)
        ->and(StorageTables::sqlState(static fn (): bool => $app->table('node_labels')->insert([
            'node_id' => $structure->northSection->id->toString(),
            'locale' => 'en',
            'label' => 'News',
            'created_at' => StorageTables::CREATED_AT,
            'updated_at' => StorageTables::CREATED_AT,
        ])))->toBe('42501')
        ->and($app->scalar("select has_table_privilege(current_user, 'node_labels', 'DELETE')"))->toBeFalse();

    $app->beginTransaction();

    try {
        new ActorContext(app(ConnectionResolverInterface::class))->set(AccessContext::anonymous());

        expect(StorageTables::texts($app, "select locale || ' ' || label as value from node_labels"))->toBe(['da Nyheder']);
    } finally {
        $app->rollBack();
    }
});
