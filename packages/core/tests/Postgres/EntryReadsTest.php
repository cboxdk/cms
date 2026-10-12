<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Content\ReleaseState;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\QueryResult;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Core\Access\Domain\AccessResolver;
use Cbox\Cms\Core\Entries\Actions\GetEntryAction;
use Cbox\Cms\Core\Entries\Actions\ListEntriesAction;
use Cbox\Cms\Core\Entries\Boundary\StoredContent;
use Cbox\Cms\Core\Entries\Domain\Dto\EntryDetail;
use Cbox\Cms\Core\Entries\Domain\Dto\EntryList;
use Cbox\Cms\Core\Entries\Domain\Dto\ListedEntry;
use Cbox\Cms\Core\Entries\Domain\EntryListing;
use Cbox\Cms\Core\Entries\Domain\Queries\GetEntry;
use Cbox\Cms\Core\Entries\Domain\Queries\ListEntries;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCall;
use Cbox\Cms\Core\Reads\Domain\Dto\QuerySettings;
use Cbox\Cms\Core\Reads\Domain\QueryAuthorizer;
use Cbox\Cms\Core\Reads\Domain\QueryTransaction;
use Cbox\Cms\Core\Reads\Domain\ReadableFields;
use Cbox\Cms\Core\Reads\Domain\ReadAudit;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;
use Cbox\Cms\Core\Tests\Entries\EntryFields;
use Cbox\Cms\Core\Tests\Entries\EntryListingWorld;
use Cbox\Cms\Core\Tests\Identity\PostgresIdentity;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeStopwatch;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryActions;
use Cbox\Cms\Core\Tests\Reads\Probe\ProbeQueryBinding;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Access\Adapter\PostgresAccessFixtures;
use Cbox\Cms\Testkit\Identity\ServiceCredentialSpec;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;

/*
 * entry.get and entry.list through the real query pipeline on Postgres (PRD 5.4, 5.7, 5.10, 8.8):
 * the Postgres credential verifier, access resolver and query authorizer, and the kernel's
 * EntryListing, over the EntryListingWorld. NORTHDESK holds a role on NORTH and SOUTHDESK one on
 * SOUTH, so each sees only the entries whose home its regions reach, in the list and in the
 * detail, with every head's fields, every placement and its window; an entry out of reach is
 * entry_not_found, and the anonymous principal is unauthorized. A page and a detail cost the same
 * statements with 20 and with 200 entries below the node (GUARDRAILS 4.1).
 */

afterEach(function (): void {
    DB::purge(StorageTables::SUPERUSER);
});

/**
 * @return array{QueryPipeline, TransportCredential, TransportCredential}
 */
function entryReadsWorld(): array
{
    $clock = new FakeClock(new DateTimeImmutable(EntryListingWorld::CREATED_AT));
    app(PartitionFixtures::class)->coverClock($clock, new DateInterval('P1D'));
    EntryListingWorld::seed();

    $identity = PostgresIdentity::at($clock);
    $fixtures = new PostgresAccessFixtures(app(DatabaseManager::class), $clock, new FakeIdGenerator(seed: 3131, clock: $clock));
    $reader = $fixtures->role('reader', ClassificationAccess::Internal);
    $credentials = [];

    foreach ([EntryListingWorld::NORTH, EntryListingWorld::SOUTH] as $node) {
        $actor = $identity->addActor(ActorClass::Service)->id;
        $fixtures->grant($actor, $reader, NodeId::fromString($node));
        $credentials[] = $identity->issue(new ServiceCredentialSpec($actor, IssuerKind::Service, ClassificationAccess::Internal, $clock->now()->add(new DateInterval('P1D'))));
    }

    $pipeline = new QueryPipeline(
        new FakeQueryActions([
            GetEntry::class => ProbeQueryBinding::of(new GetEntryAction(app(EntryListing::class)), 'entry.get', 1),
            ListEntries::class => ProbeQueryBinding::of(new ListEntriesAction(app(EntryListing::class)), 'entry.list', 1),
        ]),
        $identity->verifier(),
        app(AccessResolver::class),
        app(QueryAuthorizer::class),
        new QuerySettings(new QueryCost(200), new QueryCost(1000)),
        new ReadableFields(app(TypeCatalog::class)),
        app(ReadAudit::class),
        app(QueryTransaction::class),
        new PipelineTelemetry(new FakeTelemetry, new FakeClock, new FakeStopwatch),
    );

    return [$pipeline, $credentials[0], $credentials[1]];
}

/**
 * Writes $count more entries of the world's type homed on NORTH_SECTION as the superuser, each
 * with its draft revision and payload, their ids above the world's.
 */
function seedEntriesBelowNorth(int $count, int $from): void
{
    $superuser = StorageTables::superuser();
    $type = EntryListingWorld::type();
    $entries = [];
    $revisions = [];
    $payloads = [];
    $heads = [];

    for ($n = 0; $n < $count; $n++) {
        $id = sprintf('0192a0c0-0000-7000-8000-0000000%05x', 0x50000 + $from + $n);
        $revision = 10000 + $from + $n;
        $entries[] = ['id' => $id, 'type_id' => $type->id->toString(), 'home_node_id' => EntryListingWorld::NORTH_SECTION, 'owner_actor_id' => null, 'lifecycle' => 'active', 'version' => 1, 'created_at' => EntryListingWorld::CREATED_AT];
        $revisions[] = ['revision_id' => $revision, 'entry_id' => $id, 'variant' => 'shared', 'rev_no' => 1, 'kind' => 'draft', 'schema_version' => $type->version, 'changeset_id' => StorageTables::CHANGESET, 'created_at' => EntryListingWorld::CREATED_AT];
        $payloads[] = ['revision_id' => $revision, 'kind' => 'draft', 'format_version' => StoredContent::FORMAT_VERSION, 'content' => StoredContent::payload(EntryFields::article('Entry '.$n))];
        $heads[] = ['entry_id' => $id, 'variant' => 'shared', 'draft_revision_id' => $revision, 'published_revision_id' => null, 'schema_version' => $type->version, 'release_state' => 'unreleased', 'version' => 1, 'created_at' => EntryListingWorld::CREATED_AT];
    }

    $superuser->table('entries')->insert($entries);
    $superuser->table('revisions')->insert($revisions);
    $superuser->table('revision_payloads')->insert($payloads);
    $superuser->table('variant_heads')->insert($heads);
}

/**
 * The statements a read of the query through the pipeline runs on the default connection.
 */
function entryReadStatements(QueryPipeline $pipeline, Query $query, TransportCredential $credential): int
{
    $connection = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    try {
        expect($pipeline->run(new QueryCall($query, $credential))->isAnswered())->toBeTrue();

        return count($connection->getQueryLog());
    } finally {
        $connection->disableQueryLog();
        $connection->flushQueryLog();
    }
}

/**
 * @return list<string>
 */
function listedIds(QueryResult $answer): array
{
    $result = $answer->result;

    return $result instanceof EntryList ? array_map(static fn (ListedEntry $entry): string => $entry->id->toString(), $result->entries) : [];
}

it('lists and gives each actor only the entries whose home its regions reach, with their heads, fields and placements', function (): void {
    [$pipeline, $northdesk, $southdesk] = entryReadsWorld();

    $north = $pipeline->run(new QueryCall(new ListEntries, $northdesk));
    $south = $pipeline->run(new QueryCall(new ListEntries, $southdesk));
    $released = $pipeline->run(new QueryCall(new ListEntries(node: NodeId::fromString(EntryListingWorld::NORTH), state: ReleaseState::Released), $northdesk));
    $detail = $pipeline->run(new QueryCall(new GetEntry(EntryId::fromString(EntryListingWorld::SECOND)), $northdesk));
    $outOfReach = $pipeline->run(new QueryCall(new GetEntry(EntryId::fromString(EntryListingWorld::SECOND)), $southdesk));
    $anonymous = $pipeline->run(new QueryCall(new ListEntries, null));
    $result = $detail->result;

    expect(listedIds($north))->toBe([EntryListingWorld::SECOND, EntryListingWorld::FIRST])
        ->and(listedIds($south))->toBe([EntryListingWorld::THIRD])
        ->and(listedIds($released))->toBe([EntryListingWorld::SECOND])
        ->and($result)->toEqual(EntryListingWorld::detail(EntryListingWorld::SECOND))
        ->and($result instanceof EntryDetail ? $result->placements[0]->locales[0]->window?->from : null)->toEqual(EntryListingWorld::at(EntryListingWorld::CREATED_AT))
        ->and($detail->contentKeys)->toHaveCount(2)
        ->and(array_map(static fn (CatalogError $error): ErrorCode => $error->code, $outOfReach->errors))->toBe([ErrorCode::EntryNotFound])
        ->and($outOfReach->errors[0]->path?->toString())->toBe('entry')
        ->and(array_map(static fn (CatalogError $error): ErrorCode => $error->code, $anonymous->errors))->toBe([ErrorCode::Unauthorized]);
});

it('costs the same statements to list a page and to read an entry with 20 and with 200 entries below the node', function (): void {
    [$pipeline, $northdesk] = entryReadsWorld();
    $counts = [];
    $seeded = 0;

    foreach ([20, 200] as $entries) {
        seedEntriesBelowNorth($entries - $seeded, $seeded);
        $seeded = $entries;
        $page = $pipeline->run(new QueryCall(new ListEntries(node: NodeId::fromString(EntryListingWorld::NORTH_SECTION), limit: 100), $northdesk));
        $counts[$entries] = [
            count(listedIds($page)),
            entryReadStatements($pipeline, new ListEntries(node: NodeId::fromString(EntryListingWorld::NORTH_SECTION), limit: 100), $northdesk),
            entryReadStatements($pipeline, new GetEntry(EntryId::fromString(EntryListingWorld::FIRST)), $northdesk),
        ];
    }

    expect($counts[20][0])->toBe(21)
        ->and($counts[200][0])->toBe(100)
        ->and($counts[200][1])->toBe($counts[20][1])
        ->and($counts[200][2])->toBe($counts[20][2]);
});
