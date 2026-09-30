<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Results\BecomesVisible;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\DryRunReport;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Tests\Entries\EntryFields;
use Cbox\Cms\Core\Tests\Entries\EntryWorld;
use Cbox\Cms\Core\Tests\Placements\PlacementStructure;
use Cbox\Cms\Core\Tests\Placements\PlacementWorld;
use Cbox\Cms\Core\Tests\Publishing\PublishingWorld;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Domain\Dto\StructureNode;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/*
 * entry.publish and entry.unpublish through the real command pipeline on Postgres (PRD 4.1, 5.6,
 * 5.10, 6.2, 6.4, invariant 1), on the structure of the testkit's structure fixtures: the sites
 * north and south, each with a section. A publish is one changeset with the events of both its
 * sub-plans, the release and the home placement going live, and its dry run lists every placement
 * that becomes visible, the ones whose windows the release opens included; an unpublish is one
 * changeset that takes the release back and closes every placement, also below nodes the actor's
 * regions do not reach, and the entry can be published again. A type with stages none only puts
 * its placement live. Both cost the same queries whatever the number of placements below the node.
 */

afterEach(function (): void {
    EntryWorld::cleanUp();
});

const PUBLISHED_ENTRY = '0192a0c0-0000-7000-8000-0000000005e1';

const HOME_PLACEMENT_ID = '0192a0c0-0000-7000-8000-0000000005c1';

const AWAY_PLACEMENT_ID = '0192a0c0-0000-7000-8000-0000000005c2';

function publishedEntry(): EntryId
{
    return EntryId::fromString(PUBLISHED_ENTRY);
}

function publishedPlacement(string $id): PlacementId
{
    return PlacementId::fromString($id);
}

/**
 * The changeset of a committed result.
 */
function publishedChangeset(WriteResult $result): string
{
    return $result->receipt->changesetId instanceof ChangesetId
        ? $result->receipt->changesetId->toString()
        : throw new LogicException('The call committed no changeset.');
}

/**
 * The events of the changeset as "<type> <aggregate id>", in the order they were written.
 *
 * @return list<string>
 */
function publishedEvents(string $changeset): array
{
    return StorageTables::texts(StorageTables::superuser(), "select type || ' ' || aggregate_id as value from events where changeset_id = ?::uuid order by event_id", [$changeset]);
}

/**
 * Each placement locale of the entry in da as "<placement> <visibility> <canonical>".
 *
 * @return list<string>
 */
function publishedLocales(string $entry = PUBLISHED_ENTRY): array
{
    return StorageTables::texts(
        StorageTables::superuser(),
        "select placement_id::text || ' ' || visibility || ' ' || canonical::text as value from placement_locales where entry_id = ?::uuid and locale = 'da' order by placement_id",
        [$entry],
    );
}

function publishedVersion(string $table, string $column, string $id): int
{
    $version = StorageTables::superuser()->table($table)->where($column, $id)->value('version');

    return is_int($version) ? $version : throw new LogicException(sprintf('No row of %s has %s.', $table, $id));
}

function publishedChangesets(): int
{
    return StorageTables::superuser()->table('changesets')->count();
}

/**
 * $count placements of entries of their own below the node, hidden in da, written as the superuser.
 */
function publishedNeighbours(StructureNode $node, int $count, int $first): void
{
    $superuser = StorageTables::superuser();

    for ($number = $first; $number < $first + $count; $number++) {
        $entry = sprintf('0192a0c0-0000-7000-9000-%012d', $number);
        $placement = sprintf('0192a0c0-0000-7000-a000-%012d', $number);
        $superuser->table('entries')->insert([...StorageTables::entry($entry), 'home_node_id' => $node->id->toString()]);
        $superuser->table('placements')->insert(StorageTables::placement($placement, $entry));
        $superuser->table('placement_generations')->insert(StorageTables::generation($placement, node: $node->id->toString()));
        $superuser->table('placement_locales')->insert(StorageTables::placementLocale([
            'placement_id' => $placement, 'entry_id' => $entry, 'node_id' => $node->id->toString(),
            'slug' => 'neighbour-'.$number, 'visibility' => 'hidden', 'canonical' => true,
        ]));
    }
}

/**
 * An article homed on the north section, placed there and below the south section, with the south
 * placement's window open since an hour ago while nothing is released.
 */
function publishedArticle(PublishingWorld $world, PlacementStructure $structure): void
{
    $entry = publishedEntry();
    $world->createEntry($entry, EntryWorld::ARTICLE, EntryFields::article('The harbour opens'), $structure->northSection, 'entry');
    $world->place(publishedPlacement(HOME_PLACEMENT_ID), $entry, $structure->northSection, $structure->north, 'harbour', 'home');
    $world->place(publishedPlacement(AWAY_PLACEMENT_ID), $entry, $structure->southSection, $structure->south, 'harbour', 'away');
    $world->setWindow(publishedPlacement(AWAY_PLACEMENT_ID), 1, new TimeWindow(new DateTimeImmutable(EntryWorld::NOW)->modify('-1 hour')), 'away-window');
}

it('publishes and unpublishes, each in exactly one changeset with the events of both sub-plans, and publishes again', function (): void {
    $structure = PlacementWorld::seed();
    $world = new PublishingWorld([$structure->north->root, $structure->south->root]);
    publishedArticle($world, $structure);
    $entry = publishedEntry();
    $home = publishedPlacement(HOME_PLACEMENT_ID);
    $before = publishedChangesets();

    $dryRun = $world->publish($entry, 1, 1, $home, publishedVersion('placements', 'id', HOME_PLACEMENT_ID), 'publish-dry', dryRun: true);
    $afterDryRun = publishedChangesets();
    $published = $world->publish($entry, 1, 1, $home, publishedVersion('placements', 'id', HOME_PLACEMENT_ID), 'publish');
    $afterPublish = publishedChangesets();
    $publishedLocales = publishedLocales();
    $publishedHead = StorageTables::texts(StorageTables::superuser(), "select release_state || ' ' || version::text as value from variant_heads where entry_id = ?::uuid", [PUBLISHED_ENTRY]);

    $unpublished = $world->unpublish($entry, 2, 'unpublish');
    $afterUnpublish = publishedChangesets();

    $again = $world->publish($entry, 3, 2, $home, publishedVersion('placements', 'id', HOME_PLACEMENT_ID), 'publish-again');

    expect($dryRun->outcome())->toBe(Outcome::DryRun)
        ->and(array_map(static fn (BecomesVisible $visible): string => $visible->placement->toString().' '.$visible->locale->value.' '.$visible->from->format('H:i'), $dryRun->dryRun instanceof DryRunReport ? $dryRun->dryRun->visible : []))
        ->toBe([HOME_PLACEMENT_ID.' da 12:00', AWAY_PLACEMENT_ID.' da 12:00'])
        ->and($afterDryRun)->toBe($before)
        ->and($published->outcome())->toBe(Outcome::Committed)
        ->and($afterPublish)->toBe($before + 1)
        ->and(publishedEvents(publishedChangeset($published)))->toBe([
            'variant.released '.PUBLISHED_ENTRY.':shared',
            'placement.visibility_changed '.HOME_PLACEMENT_ID,
        ])
        ->and($publishedHead)->toBe(['released 2'])
        ->and($publishedLocales)->toBe([HOME_PLACEMENT_ID.' live false', AWAY_PLACEMENT_ID.' live true'])
        ->and($unpublished->outcome())->toBe(Outcome::Committed)
        ->and($afterUnpublish)->toBe($before + 2)
        ->and(publishedEvents(publishedChangeset($unpublished)))->toBe([
            'variant.unreleased '.PUBLISHED_ENTRY.':shared',
            'placement.visibility_changed '.HOME_PLACEMENT_ID,
            'placement.visibility_changed '.AWAY_PLACEMENT_ID,
        ])
        ->and(StorageTables::superuser()->table('changesets')->where('changeset_id', publishedChangeset($unpublished))->value('command'))->toBe('entry.unpublish')
        ->and($again->outcome())->toBe(Outcome::Committed)
        ->and(StorageTables::texts(StorageTables::superuser(), "select action || ' ' || coalesce(r.rev_no::text, '-') as value from release_log l left join revisions r on r.revision_id = l.revision_id where l.entry_id = ?::uuid order by release_id", [PUBLISHED_ENTRY]))
        ->toBe(['released 2', 'unreleased -', 'released 2'])
        ->and(publishedLocales())->toBe([HOME_PLACEMENT_ID.' live true', AWAY_PLACEMENT_ID.' hidden false']);
});

it('takes the release back: the head, the release log and the type table\'s rows, and hides the placements', function (): void {
    $structure = PlacementWorld::seed();
    $world = new PublishingWorld([$structure->north->root, $structure->south->root]);
    publishedArticle($world, $structure);
    $entry = publishedEntry();
    $world->publish($entry, 1, 1, publishedPlacement(HOME_PLACEMENT_ID), publishedVersion('placements', 'id', HOME_PLACEMENT_ID), 'publish');
    $released = StorageTables::texts(StorageTables::superuser(), 'select cms_stage || \' \' || fixture_title as value from app__fixture_article where cms_entry_id = ?::uuid order by cms_stage', [PUBLISHED_ENTRY]);

    $result = $world->unpublish($entry, 2, 'unpublish');
    $window = StorageTables::texts(
        StorageTables::superuser(),
        "select coalesce(live_from::text, '-') || ' ' || coalesce(live_until::text, '-') || ' ' || coalesce(next_transition_at::text, '-') as value from placement_locales where entry_id = ?::uuid order by placement_id",
        [PUBLISHED_ENTRY],
    );

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($released)->toBe(['released The harbour opens'])
        ->and(StorageTables::texts(StorageTables::superuser(), "select release_state || ' ' || coalesce(published_revision_id::text, '-') || ' ' || version::text as value from variant_heads where entry_id = ?::uuid", [PUBLISHED_ENTRY]))
        ->toBe(['unreleased - 3'])
        ->and(StorageTables::texts(StorageTables::superuser(), 'select cms_stage || \' \' || fixture_title as value from app__fixture_article where cms_entry_id = ?::uuid order by cms_stage', [PUBLISHED_ENTRY]))
        ->toBe(['draft The harbour opens'])
        ->and(StorageTables::superuser()->table('release_log')->where('entry_id', PUBLISHED_ENTRY)->where('action', 'unreleased')->value('changeset_id'))->toBe(publishedChangeset($result))
        ->and(publishedLocales())->toBe([HOME_PLACEMENT_ID.' hidden false', AWAY_PLACEMENT_ID.' hidden true'])
        ->and($window)->toBe(['- - -', '- - -']);
});

it('closes the placements below nodes the actor\'s regions do not reach, because unpublishing is decided on the home', function (): void {
    $structure = PlacementWorld::seed();
    $national = new PublishingWorld([$structure->north->root, $structure->south->root]);
    $home = new PublishingWorld([$structure->north->root], seed: 2);
    publishedArticle($national, $structure);
    $national->publish(publishedEntry(), 1, 1, publishedPlacement(HOME_PLACEMENT_ID), publishedVersion('placements', 'id', HOME_PLACEMENT_ID), 'publish');

    $result = $home->unpublish(publishedEntry(), 2, 'unpublish');

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and(publishedLocales())->toBe([HOME_PLACEMENT_ID.' hidden false', AWAY_PLACEMENT_ID.' hidden true'])
        ->and(StorageTables::texts(StorageTables::superuser(), "select array_to_string(aggregates, ' ') as value from audit where command = 'entry.unpublish'", []))
        ->toBe([implode(' ', ['variant:'.PUBLISHED_ENTRY.':shared', 'placement:'.HOME_PLACEMENT_ID, 'placement:'.AWAY_PLACEMENT_ID])]);
});

it('only puts the placement of a type with stages none live, in one changeset without a release', function (): void {
    $structure = PlacementWorld::seed();
    $world = new PublishingWorld([$structure->north->root]);
    $entry = publishedEntry();
    $world->createEntry($entry, EntryWorld::MEASUREMENT, EntryFields::measurement(), $structure->northSection, 'entry');
    $world->place(publishedPlacement(HOME_PLACEMENT_ID), $entry, $structure->northSection, $structure->north, 'reading', 'home');
    $before = publishedChangesets();

    $result = $world->publish($entry, 1, null, publishedPlacement(HOME_PLACEMENT_ID), 1, 'publish');
    $unpublished = $world->unpublish($entry, 1, 'unpublish');

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and(publishedChangesets())->toBe($before + 2)
        ->and(publishedEvents(publishedChangeset($result)))->toBe(['placement.visibility_changed '.HOME_PLACEMENT_ID])
        ->and(StorageTables::superuser()->table('release_log')->where('entry_id', PUBLISHED_ENTRY)->count())->toBe(0)
        ->and(publishedEvents(publishedChangeset($unpublished)))->toBe(['placement.visibility_changed '.HOME_PLACEMENT_ID])
        ->and(publishedLocales())->toBe([HOME_PLACEMENT_ID.' hidden true']);
});

it('rejects a publish of a placement that is not the home placement and keeps nothing of it', function (): void {
    $structure = PlacementWorld::seed();
    $world = new PublishingWorld([$structure->north->root, $structure->south->root]);
    publishedArticle($world, $structure);
    $before = publishedChangesets();

    $result = $world->publish(publishedEntry(), 1, 1, publishedPlacement(AWAY_PLACEMENT_ID), publishedVersion('placements', 'id', AWAY_PLACEMENT_ID), 'publish-away');

    expect(array_map(static fn (CatalogError $error): string => $error->code->value.' '.($error->path?->toString() ?? '-'), $result->errors))->toBe(['validation_failed placement'])
        ->and(publishedChangesets())->toBe($before)
        ->and(StorageTables::superuser()->table('release_log')->count())->toBe(0);
});

it('costs the same queries to publish and to unpublish whether 20 or 200 placements are below the home node', function (): void {
    $structure = PlacementWorld::seed();
    $world = new PublishingWorld([$structure->north->root, $structure->south->root]);
    $counts = [];

    foreach ([20, 200] as $others) {
        publishedNeighbours($structure->northSection, $others, $others * 1000);
        $entry = EntryId::fromString(sprintf('0192a0c0-0000-7000-8000-%012d', 6000 + $others));
        $home = PlacementId::fromString(sprintf('0192a0c0-0000-7000-8000-%012d', 7000 + $others));
        $world->createEntry($entry, EntryWorld::ARTICLE, EntryFields::article(), $structure->northSection, 'entry-'.$others);
        $world->place($home, $entry, $structure->northSection, $structure->north, 'counted-'.$others, 'place-'.$others);

        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $published = $world->publish($entry, 1, 1, $home, 1, 'publish-'.$others);
        $publishing = count($connection->getQueryLog());
        $connection->flushQueryLog();
        $unpublished = $world->unpublish($entry, 2, 'unpublish-'.$others);
        $unpublishing = count($connection->getQueryLog());
        $connection->disableQueryLog();
        $connection->flushQueryLog();

        $counts[$others] = [$published->outcome(), $unpublished->outcome(), $publishing, $unpublishing];
    }

    expect($counts[20][0])->toBe(Outcome::Committed)
        ->and($counts[20][1])->toBe(Outcome::Committed)
        ->and($counts[200][0])->toBe(Outcome::Committed)
        ->and($counts[200][1])->toBe(Outcome::Committed)
        ->and(StorageTables::superuser()->table('placement_generations')->where('node_id', $structure->northSection->id->toString())->count())->toBe(222)
        ->and($counts[200][2])->toBe($counts[20][2])
        ->and($counts[200][3])->toBe($counts[20][3]);
});
