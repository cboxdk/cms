<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Tests\Entries\EntryWorld;
use Cbox\Cms\Core\Tests\Placements\PlacementWorld;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Domain\Dto\StructureNode;
use Cbox\Cms\Testkit\Postgres\IndependentConnections;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/*
 * placement.create and placement.set_window through the real command pipeline on Postgres (PRD 4.1,
 * 5.7, 5.9, 5.10, 6.4, invariants 14 and 15), on the structure of the testkit's structure fixtures:
 * the sites north and south, each with a section. An entry placed on both sites has exactly one
 * canonical placement once both are visible, and the flag moves to a visible placement, also across
 * the actor's regions; a slug another placement has below the node and a node outside the actor's
 * regions are refused; two commands that claim one slug, or would each make a placement canonical,
 * commit one after the other; and a command costs the same queries whatever the number of
 * placements below the node.
 */

afterEach(function (): void {
    EntryWorld::cleanUp();
});

const PLACEMENT_ENTRY = '0192a0c0-0000-7000-8000-0000000004e1';

const NORTH_PLACEMENT = '0192a0c0-0000-7000-8000-0000000004c1';

const SOUTH_PLACEMENT_ID = '0192a0c0-0000-7000-8000-0000000004c2';

/**
 * @return list<string>
 */
function placementCodes(WriteResult $result): array
{
    return array_map(static fn (CatalogError $error): string => $error->code->value, $result->errors);
}

/**
 * Each placement locale of the entry in da, as "<placement> <visibility> <canonical>", in the order
 * of the placements, read as the superuser.
 *
 * @return list<string>
 */
function placementLocales(string $entry = PLACEMENT_ENTRY): array
{
    return StorageTables::texts(
        StorageTables::superuser(),
        "select placement_id::text || ' ' || visibility || ' ' || canonical::text as value from placement_locales where entry_id = ?::uuid and locale = 'da' order by placement_id",
        [$entry],
    );
}

function placementId(string $id): PlacementId
{
    return PlacementId::fromString($id);
}

/**
 * $count placements of entries of their own below the node, hidden in da, written as the superuser.
 */
function placementNeighbours(StructureNode $node, int $count, int $first): void
{
    $entries = [];
    $placements = [];
    $generations = [];
    $locales = [];

    for ($number = $first; $number < $first + $count; $number++) {
        $entry = sprintf('0192a0c0-0000-7000-9000-%012d', $number);
        $placement = sprintf('0192a0c0-0000-7000-a000-%012d', $number);
        $entries[] = [...StorageTables::entry($entry), 'home_node_id' => $node->id->toString()];
        $placements[] = StorageTables::placement($placement, $entry);
        $generations[] = StorageTables::generation($placement, node: $node->id->toString());
        $locales[] = StorageTables::placementLocale([
            'placement_id' => $placement, 'entry_id' => $entry, 'node_id' => $node->id->toString(),
            'slug' => 'neighbour-'.$number, 'visibility' => 'hidden', 'canonical' => true,
        ]);
    }

    $superuser = StorageTables::superuser();

    foreach ([['entries', $entries], ['placements', $placements], ['placement_generations', $generations], ['placement_locales', $locales]] as [$table, $rows]) {
        foreach (array_chunk($rows, 100) as $chunk) {
            $superuser->table($table)->insert($chunk);
        }
    }
}

it('places one entry on two sites and keeps exactly one canonical placement when both are visible', function (): void {
    $structure = PlacementWorld::seed();
    $world = new PlacementWorld([$structure->north->root, $structure->south->root]);
    $entry = EntryId::fromString(PLACEMENT_ENTRY);
    $now = new DateTimeImmutable(EntryWorld::NOW);

    expect($world->createEntry($entry, $structure->northSection, 'entry')->outcome())->toBe(Outcome::Committed)
        ->and($world->place(placementId(NORTH_PLACEMENT), $entry, $structure->northSection, $structure->north, ['da' => 'harbour', 'en' => 'harbour'], 'north')->outcome())->toBe(Outcome::Committed)
        ->and($world->place(placementId(SOUTH_PLACEMENT_ID), $entry, $structure->southSection, $structure->south, ['da' => 'harbour'], 'south')->outcome())->toBe(Outcome::Committed)
        ->and(placementLocales())->toBe([NORTH_PLACEMENT.' hidden true', SOUTH_PLACEMENT_ID.' hidden false'])
        ->and($world->setWindow(placementId(SOUTH_PLACEMENT_ID), 1, new TimeWindow($now), 'south-live')->outcome())->toBe(Outcome::Committed)
        ->and(placementLocales())->toBe([NORTH_PLACEMENT.' hidden false', SOUTH_PLACEMENT_ID.' live true'])
        ->and($world->setWindow(placementId(NORTH_PLACEMENT), 2, new TimeWindow($now->modify('-1 hour')), 'north-live')->outcome())->toBe(Outcome::Committed)
        ->and(placementLocales())->toBe([NORTH_PLACEMENT.' live false', SOUTH_PLACEMENT_ID.' live true'])
        ->and(StorageTables::superuser()->table('placement_locales')->where('entry_id', PLACEMENT_ENTRY)->where('locale', 'da')->where('canonical', true)->count())->toBe(1)
        ->and($world->setWindow(placementId(SOUTH_PLACEMENT_ID), 2, null, 'south-hidden')->outcome())->toBe(Outcome::Committed)
        ->and(placementLocales())->toBe([NORTH_PLACEMENT.' live true', SOUTH_PLACEMENT_ID.' hidden false'])
        ->and(StorageTables::texts(StorageTables::superuser(), "select type || ' ' || aggregate_id::text || ' ' || aggregate_version::text as value from events where type like 'placement.%' order by event_id", []))->toBe([
            'placement.created '.NORTH_PLACEMENT.' 1',
            'placement.created '.SOUTH_PLACEMENT_ID.' 1',
            'placement.visibility_changed '.SOUTH_PLACEMENT_ID.' 2',
            'placement.visibility_changed '.NORTH_PLACEMENT.' 3',
            'placement.visibility_changed '.SOUTH_PLACEMENT_ID.' 3',
        ])
        ->and(StorageTables::superuser()->table('changesets')->where('command', 'placement.set_window')->count())->toBe(3)
        ->and(StorageTables::superuser()->table('placements')->where('id', NORTH_PLACEMENT)->value('version'))->toBe(4);
});

it('stores the state a window gives at the commit and the time of its next transition', function (): void {
    $structure = PlacementWorld::seed();
    $world = new PlacementWorld([$structure->north->root]);
    $entry = EntryId::fromString(PLACEMENT_ENTRY);
    $now = new DateTimeImmutable(EntryWorld::NOW);
    $world->createEntry($entry, $structure->northSection, 'entry');
    $world->place(placementId(NORTH_PLACEMENT), $entry, $structure->northSection, $structure->north, ['da' => 'harbour'], 'north');

    $row = static fn (): string => StorageTables::texts(
        StorageTables::superuser(),
        "select visibility || ' ' || coalesce(to_char(live_from at time zone 'UTC', 'HH24:MI'), '-') || ' ' || coalesce(to_char(live_until at time zone 'UTC', 'HH24:MI'), '-') || ' ' || coalesce(to_char(next_transition_at at time zone 'UTC', 'HH24:MI'), '-') as value from placement_locales where placement_id = ?::uuid",
        [NORTH_PLACEMENT],
    )[0];

    $world->setWindow(placementId(NORTH_PLACEMENT), 1, new TimeWindow($now->modify('+2 hours'), $now->modify('+5 hours')), 'scheduled');
    $scheduled = $row();
    $world->setWindow(placementId(NORTH_PLACEMENT), 2, new TimeWindow($now->modify('-2 hours'), $now->modify('+5 hours')), 'live');
    $live = $row();
    $world->setWindow(placementId(NORTH_PLACEMENT), 3, new TimeWindow($now->modify('-5 hours'), $now->modify('-2 hours')), 'expired');
    $expired = $row();
    $world->setWindow(placementId(NORTH_PLACEMENT), 4, null, 'hidden');

    expect($scheduled)->toBe('scheduled 14:00 17:00 14:00')
        ->and($live)->toBe('live 10:00 17:00 17:00')
        ->and($expired)->toBe('expired 07:00 10:00 -')
        ->and($row())->toBe('hidden - - -');
});

it('moves the canonical flag off a placement below a node the actor cannot reach', function (): void {
    $structure = PlacementWorld::seed();
    $national = new PlacementWorld([$structure->north->root, $structure->south->root]);
    $regional = new PlacementWorld([$structure->south->root], seed: 2);
    $entry = EntryId::fromString(PLACEMENT_ENTRY);
    $national->createEntry($entry, $structure->northSection, 'entry');
    $national->place(placementId(NORTH_PLACEMENT), $entry, $structure->northSection, $structure->north, ['da' => 'harbour'], 'north');
    $national->place(placementId(SOUTH_PLACEMENT_ID), $entry, $structure->southSection, $structure->south, ['da' => 'harbour'], 'south');

    $result = $regional->setWindow(placementId(SOUTH_PLACEMENT_ID), 1, new TimeWindow(new DateTimeImmutable(EntryWorld::NOW)), 'south-live');

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and(placementLocales())->toBe([NORTH_PLACEMENT.' hidden false', SOUTH_PLACEMENT_ID.' live true'])
        ->and(StorageTables::superuser()->table('placements')->where('id', NORTH_PLACEMENT)->value('version'))->toBe(2)
        ->and(StorageTables::texts(StorageTables::superuser(), "select array_to_string(aggregates, ' ') as value from audit where command = 'placement.set_window'", []))
        ->toBe([implode(' ', ['placement:'.SOUTH_PLACEMENT_ID, 'placement:'.NORTH_PLACEMENT])]);
});

it('refuses a slug another placement has below the node, and a node outside the actor\'s regions', function (): void {
    $structure = PlacementWorld::seed();
    $world = new PlacementWorld([$structure->north->root, $structure->south->root]);
    $regional = new PlacementWorld([$structure->south->root], seed: 2);
    $entry = EntryId::fromString(PLACEMENT_ENTRY);
    $world->createEntry($entry, $structure->northSection, 'entry');
    $world->place(placementId(NORTH_PLACEMENT), $entry, $structure->northSection, $structure->north, ['da' => 'harbour'], 'north');

    $taken = $world->place(placementId(SOUTH_PLACEMENT_ID), $entry, $structure->northSection, $structure->north, ['da' => 'harbour'], 'taken');
    $outside = $regional->place(placementId(SOUTH_PLACEMENT_ID), $entry, $structure->northSection, $structure->north, ['da' => 'harbour-2'], 'outside');
    $window = $regional->setWindow(placementId(NORTH_PLACEMENT), 1, new TimeWindow(new DateTimeImmutable(EntryWorld::NOW)), 'outside-window');

    expect(placementCodes($taken))->toBe(['placement_slug_taken'])
        ->and(placementCodes($outside))->toBe(['unauthorized'])
        ->and(placementCodes($window))->toBe(['unauthorized'])
        ->and(StorageTables::superuser()->table('placements')->count())->toBe(1)
        ->and(placementLocales())->toBe([NORTH_PLACEMENT.' hidden true']);
});

it('commits one of two placements that claim one slug at the same time and rejects the other with version_conflict', function (): void {
    $structure = PlacementWorld::seed();
    [$first, $second] = app(IndependentConnections::class)->open(2);
    $regions = [$structure->north->root];
    $one = new PlacementWorld($regions, $first->getName(), seed: 1);
    $other = new PlacementWorld($regions, $second->getName(), seed: 2);
    $entry = EntryId::fromString(PLACEMENT_ENTRY);
    $one->createEntry($entry, $structure->northSection, 'entry');

    $winner = null;
    $one->meanwhile = static function () use ($other, $entry, $structure, &$winner): void {
        $winner = $other->place(placementId(SOUTH_PLACEMENT_ID), $entry, $structure->northSection, $structure->north, ['da' => 'harbour'], 'second');
    };
    $loser = $one->place(placementId(NORTH_PLACEMENT), $entry, $structure->northSection, $structure->north, ['da' => 'harbour'], 'first');

    expect($winner?->outcome())->toBe(Outcome::Committed)
        ->and(placementCodes($loser))->toBe(['version_conflict', 'version_conflict'])
        ->and(implode(' ', array_map(static fn (CatalogError $error): string => $error->message, $loser->errors)))
        ->toContain('placement_slug:'.$structure->northSection->id->toString().':da:harbour')
        ->toContain('placement_canonical:'.PLACEMENT_ENTRY.':da')
        ->and(placementLocales())->toBe([SOUTH_PLACEMENT_ID.' hidden true']);
});

it('costs the same queries to place an entry and set its window whether 20 or 200 placements are below the node', function (): void {
    $structure = PlacementWorld::seed();
    $world = new PlacementWorld([$structure->north->root]);
    $counts = [];

    foreach ([20, 200] as $others) {
        placementNeighbours($structure->northSection, $others, $others * 1000);
        $entry = EntryId::fromString(sprintf('0192a0c0-0000-7000-8000-%012d', 6000 + $others));
        $placement = placementId(sprintf('0192a0c0-0000-7000-8000-%012d', 7000 + $others));
        $world->createEntry($entry, $structure->northSection, 'entry-'.$others);

        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $placed = $world->place($placement, $entry, $structure->northSection, $structure->north, ['da' => 'counted-'.$others], 'place-'.$others);
        $placing = count($connection->getQueryLog());
        $connection->flushQueryLog();
        $opened = $world->setWindow($placement, 1, new TimeWindow(new DateTimeImmutable(EntryWorld::NOW)), 'window-'.$others);
        $opening = count($connection->getQueryLog());
        $connection->disableQueryLog();
        $connection->flushQueryLog();

        $counts[$others] = [$placed->outcome(), $opened->outcome(), $placing, $opening];
    }

    expect($counts[20][0])->toBe(Outcome::Committed)
        ->and($counts[20][1])->toBe(Outcome::Committed)
        ->and($counts[200][0])->toBe(Outcome::Committed)
        ->and($counts[200][1])->toBe(Outcome::Committed)
        ->and(StorageTables::superuser()->table('placement_generations')->where('node_id', $structure->northSection->id->toString())->count())->toBe(222)
        ->and($counts[200][2])->toBe($counts[20][2])
        ->and($counts[200][3])->toBe($counts[20][3]);
});
