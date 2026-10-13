<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Codecs\RecordCodecs;
use Cbox\Cms\Contracts\Content\ReleaseState;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\QueryResult;
use Cbox\Cms\Contracts\Results\ReadContent;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Core\Access\Domain\AccessResolver;
use Cbox\Cms\Core\Entries\Actions\PreviewEntryAction;
use Cbox\Cms\Core\Entries\Domain\Dto\EntryPreview;
use Cbox\Cms\Core\Entries\Domain\Queries\PreviewEntry;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCall;
use Cbox\Cms\Core\Reads\Domain\Dto\QuerySettings;
use Cbox\Cms\Core\Reads\Domain\QueryAuthorizer;
use Cbox\Cms\Core\Reads\Domain\QueryTransaction;
use Cbox\Cms\Core\Reads\Domain\ReadableFields;
use Cbox\Cms\Core\Reads\Domain\ReadAudit;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;
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
use Cbox\Cms\Testkit\Valkey\ValkeyRun;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;

/*
 * entry.preview through the real query pipeline on Postgres and Valkey (PRD 5.4, 8.9, 5.10,
 * GUARDRAILS 4.1): the Postgres credential verifier, access resolver and query authorizer, the
 * container's PreviewEntryAction with PostgresEntryPreviews and the workbench's record codecs, over
 * the EntryListingWorld, whose SECOND entry is released at revision 2 while its draft, revision 1,
 * holds another title. A reader granted on NORTH previews the draft and the released revision, each
 * the record the delivery API writes at the public classification access; an entry out of reach is
 * entry_not_found and a revision the variant does not have revision_not_found. A preview writes no
 * row and no fragment: the run's Valkey prefix holds no key after it. It costs the same statements
 * with 20 and with 200 placements of the entry, because it reads none.
 */

afterEach(function (): void {
    DB::purge(StorageTables::SUPERUSER);
});

/**
 * The query pipeline of entry.preview and the credential of a reader granted on NORTH.
 *
 * @return array{QueryPipeline, TransportCredential}
 */
function entryPreviewWorld(): array
{
    $clock = new FakeClock(new DateTimeImmutable(EntryListingWorld::CREATED_AT));
    app(PartitionFixtures::class)->coverClock($clock, new DateInterval('P1D'));
    EntryListingWorld::seed();

    $identity = PostgresIdentity::at($clock);
    $fixtures = new PostgresAccessFixtures(app(DatabaseManager::class), $clock, new FakeIdGenerator(seed: 3434, clock: $clock));
    $actor = $identity->addActor(ActorClass::Service)->id;
    $fixtures->grant($actor, $fixtures->role('previewer', ClassificationAccess::Internal), NodeId::fromString(EntryListingWorld::NORTH));

    $pipeline = new QueryPipeline(
        new FakeQueryActions([PreviewEntry::class => ProbeQueryBinding::of(app(PreviewEntryAction::class), 'entry.preview', 1)]),
        $identity->verifier(),
        app(AccessResolver::class),
        app(QueryAuthorizer::class),
        new QuerySettings(new QueryCost(200), new QueryCost(1000)),
        new ReadableFields(app(TypeCatalog::class)),
        app(ReadAudit::class),
        app(QueryTransaction::class),
        new PipelineTelemetry(new FakeTelemetry, new FakeClock, new FakeStopwatch),
    );

    return [$pipeline, $identity->issue(new ServiceCredentialSpec($actor, IssuerKind::Service, ClassificationAccess::Internal, $clock->now()->add(new DateInterval('P1D'))))];
}

/**
 * Writes $count placements of SECOND below NORTH_SECTION in da as the superuser, each hidden.
 */
function seedPreviewPlacements(int $count, int $from): void
{
    $superuser = StorageTables::superuser();
    $placements = [];
    $generations = [];
    $locales = [];

    for ($n = 0; $n < $count; $n++) {
        $id = sprintf('0192a0c0-0000-7000-8000-0000000%05x', 0x60000 + $from + $n);
        $placements[] = ['id' => $id, 'entry_id' => EntryListingWorld::SECOND, 'version' => 1, 'created_at' => EntryListingWorld::CREATED_AT];
        $generations[] = ['placement_id' => $id, 'stage' => 'released', 'node_id' => EntryListingWorld::NORTH_SECTION, 'created_at' => EntryListingWorld::CREATED_AT];
        $locales[] = [
            'placement_id' => $id, 'stage' => 'released', 'locale' => 'da', 'entry_id' => EntryListingWorld::SECOND, 'node_id' => EntryListingWorld::NORTH_SECTION,
            'slug' => 'preview-'.($from + $n), 'visibility' => 'hidden', 'live_from' => null, 'live_until' => null, 'next_transition_at' => null,
            'canonical' => false, 'created_at' => EntryListingWorld::CREATED_AT,
        ];
    }

    foreach (array_chunk($placements, 100) as $chunk) {
        $superuser->table('placements')->insert($chunk);
    }

    foreach (array_chunk($generations, 100) as $chunk) {
        $superuser->table('placement_generations')->insert($chunk);
    }

    foreach (array_chunk($locales, 100) as $chunk) {
        $superuser->table('placement_locales')->insert($chunk);
    }
}

/**
 * The statements a preview through the pipeline runs on the default connection, each lowercased.
 *
 * @return list<string>
 */
function previewStatements(QueryPipeline $pipeline, PreviewEntry $query, TransportCredential $credential): array
{
    $connection = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    try {
        expect($pipeline->run(new QueryCall($query, $credential))->isAnswered())->toBeTrue();

        $statements = [];

        foreach ($connection->getQueryLog() as $logged) {
            $statements[] = strtolower(trim($logged['query']));
        }

        return $statements;
    } finally {
        $connection->disableQueryLog();
        $connection->flushQueryLog();
    }
}

/**
 * The catalog codes of a rejected preview, in order.
 *
 * @return list<ErrorCode>
 */
function previewCodes(QueryResult $answer): array
{
    return array_map(static fn (CatalogError $error): ErrorCode => $error->code, $answer->errors);
}

it('previews the draft and the released revision as the delivery API writes the record, and refuses what is out of reach', function (): void {
    [$pipeline, $credential] = entryPreviewWorld();
    $second = EntryListingWorld::detail(EntryListingWorld::SECOND);
    $record = static fn (string $title): string => app(RecordCodecs::class)->encode(
        new ReadContent($second->id, $second->home, $second->type, $title === 'Second' ? $second->heads[0]->fields : EntryListingWorld::releasedFields($second)),
        ClassificationAccess::Public,
    );

    $draft = $pipeline->run(new QueryCall(new PreviewEntry($second->id), $credential));
    $released = $pipeline->run(new QueryCall(new PreviewEntry($second->id, revision: new RevisionNumber(2)), $credential));
    $missing = $pipeline->run(new QueryCall(new PreviewEntry($second->id, revision: new RevisionNumber(9)), $credential));
    $outOfReach = $pipeline->run(new QueryCall(new PreviewEntry(EntryId::fromString(EntryListingWorld::THIRD)), $credential));

    expect($draft->result)->toBeInstanceOf(EntryPreview::class)
        ->and($draft->result instanceof EntryPreview ? [$draft->result->revision->value, $draft->result->draft->value, $draft->result->released?->value, $draft->result->state, $draft->result->type->value, $draft->result->data->value] : null)
        ->toBe([1, 1, 2, ReleaseState::Released, 'app:fixture_article', $record('Second')])
        ->and($released->result instanceof EntryPreview ? $released->result->data->value : null)->toBe($record('Second, released'))
        ->and($draft->contentKeys)->toBe([])
        ->and(previewCodes($missing))->toBe([ErrorCode::RevisionNotFound])
        ->and(previewCodes($outOfReach))->toBe([ErrorCode::EntryNotFound]);
});

it('writes no row and no fragment', function (): void {
    [$pipeline, $credential] = entryPreviewWorld();
    $statements = previewStatements($pipeline, new PreviewEntry(EntryId::fromString(EntryListingWorld::SECOND)), $credential);
    $writes = array_filter($statements, static fn (string $statement): bool => preg_match('/\A(insert|update|delete|merge|truncate|copy)\b/', $statement) === 1);

    expect($writes)->toBe([])
        ->and(app(ValkeyRun::class)->keys())->toBe([])
        ->and(StorageTables::superuser()->table('read_audit')->count())->toBe(0);
});

it('costs the same statements with 20 and with 200 placements of the entry', function (): void {
    [$pipeline, $credential] = entryPreviewWorld();
    $counts = [];
    $seeded = 0;

    foreach ([20, 200] as $placements) {
        seedPreviewPlacements($placements - $seeded, $seeded);
        $seeded = $placements;
        $counts[$placements] = count(previewStatements($pipeline, new PreviewEntry(EntryId::fromString(EntryListingWorld::SECOND)), $credential));
    }

    expect(StorageTables::superuser()->table('placements')->where('entry_id', EntryListingWorld::SECOND)->count())->toBe(201)
        ->and($counts[200])->toBe($counts[20]);
});
