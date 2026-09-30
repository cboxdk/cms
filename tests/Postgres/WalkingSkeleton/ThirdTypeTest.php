<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Postgres\WalkingSkeleton;

use Cbox\Cms\Cli\Boundary\CliCredential;
use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Cache\FragmentStore;
use Cbox\Cms\Contracts\Cdn\CdnDriver;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Consistency\ProjectionState;
use Cbox\Cms\Contracts\Fields\BooleanValue;
use Cbox\Cms\Contracts\Fields\DateTimeValue;
use Cbox\Cms\Contracts\Fields\DateValue;
use Cbox\Cms\Contracts\Fields\DecimalValue;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\GroupValue;
use Cbox\Cms\Contracts\Fields\IntegerValue;
use Cbox\Cms\Contracts\Fields\ListValue;
use Cbox\Cms\Contracts\Fields\MapEntry;
use Cbox\Cms\Contracts\Fields\MapValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\ReceiptStore;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Contracts\Schema\History;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Core\Fragments\Actions\InvalidateFragments;
use Cbox\Cms\Core\Operations\Domain\OperationState;
use Cbox\Cms\Core\Pipeline\Domain\CommandAuthorizer;
use Cbox\Cms\Core\ReadModels\Domain\Dto\ChunkResult;
use Cbox\Cms\Core\Subscriptions\Actions\RunLane;
use Cbox\Cms\Core\Subscriptions\Domain\Dto\LaneRun;
use Cbox\Cms\Core\Tests\Entries\EntryFields;
use Cbox\Cms\Core\Tests\Entries\EntryWorld;
use Cbox\Cms\Core\Tests\Identity\PostgresIdentity;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandAuthorizer;
use Cbox\Cms\Core\Tests\Placements\PlacementStructure;
use Cbox\Cms\Core\Tests\Placements\PlacementWorld;
use Cbox\Cms\Core\Tests\Postgres\StorageTables;
use Cbox\Cms\Core\Tests\Publishing\PublishingWorld;
use Cbox\Cms\Core\Tests\ReadModels\RebuildWorld;
use Cbox\Cms\Core\Tests\Subscriptions\Fakes\StopAfterRounds;
use Cbox\Cms\Http\Delivery\Boundary\DeliveryOutput;
use Cbox\Cms\Testkit\Cdn\FakeCdnDriver;
use Cbox\Cms\Testkit\FixtureWriters\Access\Adapter\PostgresAccessFixtures;
use Cbox\Cms\Testkit\Identity\ServiceCredentialSpec;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Tests\Support\SurfaceContract\SurfaceContractCase;
use Cbox\Cms\Tests\Support\SurfaceContract\SurfaceContractCases;
use Cbox\Cms\Tests\Support\SurfaceContract\SurfaceProfiles;
use Cbox\Cms\Tests\TestCase;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use Pest\TestSuite;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;

/*
 * MILESTONES M1 exit criterion, GUARDRAILS 2.4, PRD 11.12: a third type, added only as the schema
 * file workbench/schema/fixture_event.yaml and the code cms:generate wrote from it, works through
 * the generators, the commands, the delivery API and every surface with no change in packages/.
 * Its capabilities differ from both other fixture types: history audit-only (head snapshots, no
 * revisions), stages none (an entry is public once it is placed and published, with no release)
 * and routable; its fields use a unit, a top-level e-mail address, rich text with links and
 * numbered lists and a group inside a group, which the other two do not.
 *
 * An event is created, revised, placed below north's section and published through the real
 * command pipeline on Postgres. GET /v1/resolve over HTTP serves it, the second time from the
 * fragment; a revise lists origin pending on its receipt, and once the critical lane's runner has
 * handled it the fragment is gone and the path is resolved again with the revised fields. The same
 * commands run through cms:run as a configured service actor. A rebuild of its type table from
 * the head snapshots gives the same rows, and a release of it is type_not_releasable, because a
 * type with stages none has no draft to release. The surface contract tests of every write action the
 * installation's registry exposes, which every type's entries are written through, pass with the
 * third type installed.
 *
 * The clock is EntryWorld::NOW, bound in the container so the delivery, the receipt store and the
 * runner read at the world's time.
 */

const THIRD_TYPE = 'app:fixture_event';

const THIRD_ENTRY = '0192a0c0-0000-7000-8000-0000000050e1';

const THIRD_PLACEMENT = '0192a0c0-0000-7000-8000-0000000050c1';

beforeEach(function (): void {
    config()->set('cbox-cms.sites', [
        'north' => ['origin' => 'https://north.example'],
        'south' => ['origin' => 'https://south.example'],
    ]);
    config()->set('cbox-cms.delivery.max_age_seconds', 300);
});

afterEach(function (): void {
    EntryWorld::cleanUp();
});

/**
 * Every field of the event but the ones a test leaves out: the name, the start, the kind, the seats
 * and price with their units, a summary, a programme in rich text with a numbered item that links,
 * whether it is free, the day the doors open, the organiser's e-mail and the venue with its address.
 */
function thirdTypeFields(string $name, int $seats = 120, string $price = '250.00'): FieldValues
{
    return EntryFields::of([
        'fixture_name' => new TextValue($name),
        'fixture_starts_at' => new DateTimeValue(new DateTimeImmutable('2026-04-01T19:30:00.000000+00:00')),
        'fixture_kind' => new TextValue('fixture_concert'),
        'fixture_seats' => new IntegerValue($seats),
        'fixture_price' => new DecimalValue($price),
        'fixture_summary' => new TextValue('An evening of harbour songs.'),
        'fixture_programme' => new ListValue(new MapValue(
            new MapEntry('_key', new TextValue('b1')),
            new MapEntry('_type', new TextValue('block')),
            new MapEntry('children', new ListValue(new MapValue(
                new MapEntry('_key', new TextValue('b1s')),
                new MapEntry('_type', new TextValue('span')),
                new MapEntry('marks', new ListValue(new TextValue('l1'), new TextValue('em'))),
                new MapEntry('text', new TextValue('The harbour choir')),
            ))),
            new MapEntry('level', new IntegerValue(1)),
            new MapEntry('listItem', new TextValue('number')),
            new MapEntry('markDefs', new ListValue(new MapValue(
                new MapEntry('_key', new TextValue('l1')),
                new MapEntry('_type', new TextValue('link')),
                new MapEntry('href', new TextValue('https://example.org/choir')),
            ))),
            new MapEntry('style', new TextValue('normal')),
        )),
        'fixture_free' => new BooleanValue(false),
        'fixture_doors_on' => new DateValue('2026-04-01'),
        'fixture_organiser_email' => new TextValue('organiser@example.org'),
        'fixture_venue' => new GroupValue(EntryFields::map([
            'fixture_venue_name' => new TextValue('The harbour hall'),
            'fixture_venue_address' => new GroupValue(EntryFields::map([
                'fixture_venue_street' => new TextValue('Kajen 1'),
                'fixture_venue_postcode' => new TextValue('8000'),
            ])),
        ])),
    ]);
}

function thirdTypeCommitted(WriteResult ...$results): void
{
    foreach ($results as $result) {
        if ($result->outcome() !== Outcome::Committed) {
            throw new AssertionFailedError('A command on the third type did not commit: '.implode('; ', array_map(static fn (CatalogError $error): string => $error->code->value.' '.$error->message, $result->errors)));
        }
    }
}

/**
 * The structure, the registry cms:build compiles (so a receipt lists origin), the container's clock
 * at the world's, the fake CDN and the critical lane's runner as a new service actor.
 */
function thirdTypeStructure(): PlacementStructure
{
    $structure = PlacementWorld::seed();
    expect(app(Kernel::class)->call('cms:build'))->toBe(0);

    return $structure;
}

function thirdTypeRunner(PublishingWorld $world): void
{
    app()->instance(Clock::class, $world->clock);
    config()->set('cbox-cms.contracts.'.CdnDriver::class, FakeCdnDriver::class);
    config()->set('cbox-cms.events.runner.service_actor', PostgresIdentity::at($world->clock)->addActor(ActorClass::Service)->id->toString());
    config()->set('cbox-cms.events.runner.idle_sleep_ms', 5);
}

/**
 * GET /v1/resolve of the event's path on north, through the application's HTTP kernel.
 *
 * @return TestResponse<Response>
 */
function thirdTypeResolve(string $slug = 'harbour-concert'): TestResponse
{
    $request = Request::create('/v1/resolve?'.http_build_query(['site' => 'north.example', 'locale' => 'da', 'path' => '/nyheder/'.$slug]));

    return TestResponse::fromBaseResponse(app(HttpKernel::class)->handle($request), $request);
}

function thirdTypeChangeset(WriteResult $result): ChangesetId
{
    return $result->receipt->changesetId ?? throw new AssertionFailedError('The write did not commit.');
}

function thirdTypeOrigin(ChangesetId $changeset): ?ProjectionState
{
    foreach (app(ReceiptStore::class)->find($changeset)->projections ?? [] as $status) {
        if ($status->projection->value === InvalidateFragments::PROJECTION) {
            return $status->state;
        }
    }

    return null;
}

/**
 * Runs the critical lane until origin is acknowledged on the changeset's receipt, for at most 30
 * seconds: the transaction horizon is server-wide, so another checkout's suite can hold it back.
 */
function thirdTypeRunUntilOrigin(ChangesetId $changeset): void
{
    $deadline = microtime(true) + 30;

    do {
        app(RunLane::class)->run(new LaneRun(Lane::Critical, untilIdle: true), new StopAfterRounds);

        if (thirdTypeOrigin($changeset) === ProjectionState::Acknowledged) {
            return;
        }

        usleep(20_000);
    } while (microtime(true) < $deadline);

    throw new AssertionFailedError('The runner did not acknowledge origin in 30 seconds.');
}

/**
 * The rows of a table for the event, each column by name, read as the superuser.
 *
 * @return list<array<string, mixed>>
 */
function thirdTypeRows(string $table, string $column, string $entry = THIRD_ENTRY): array
{
    $rows = [];

    foreach (StorageTables::superuser()->table($table)->where($column, $entry)->get() as $row) {
        $values = [];

        foreach (get_object_vars($row) as $name => $value) {
            $values[(string) $name] = $value;
        }

        $rows[] = $values;
    }

    return $rows;
}

it('is installed from the schema file with capabilities neither other fixture type has', function (): void {
    $type = EntryWorld::type(THIRD_TYPE);
    $capabilities = static fn (string $name): array => [
        EntryWorld::type($name)->capabilities->history,
        EntryWorld::type($name)->capabilities->stages,
        EntryWorld::type($name)->capabilities->routable,
    ];

    expect($capabilities(THIRD_TYPE))->toBe([History::AuditOnly, Stages::None, true])
        ->and($capabilities(THIRD_TYPE))->not->toBe($capabilities(EntryWorld::ARTICLE))
        ->and($capabilities(THIRD_TYPE))->not->toBe($capabilities(EntryWorld::MEASUREMENT))
        ->and($type->name->table())->toBe('app__fixture_event')
        ->and(StorageTables::superuser()->table('app__fixture_event')->count())->toBe(0);
});

it('creates, revises, places and publishes an event, keeps head snapshots and no revisions, and serves it over GET /v1/resolve', function (): void {
    $structure = thirdTypeStructure();
    $world = new PublishingWorld([$structure->north->root, $structure->south->root]);
    thirdTypeRunner($world);
    $entry = EntryId::fromString(THIRD_ENTRY);
    $placement = PlacementId::fromString(THIRD_PLACEMENT);

    thirdTypeCommitted(
        $world->createEntry($entry, THIRD_TYPE, thirdTypeFields('Harbour songs'), $structure->northSection, 'third-create'),
        $world->revise($entry, 1, thirdTypeFields('Harbour songs at dusk', 140), 'third-revise'),
        $world->place($placement, $entry, $structure->northSection, $structure->north, 'harbour-concert', 'third-place'),
    );
    $publish = $world->publish($entry, 2, null, $placement, 1, 'third-publish');
    thirdTypeCommitted($publish);

    $snapshots = thirdTypeRows('head_snapshots', 'entry_id');
    $rows = thirdTypeRows('app__fixture_event', 'cms_entry_id');

    expect(StorageTables::superuser()->table('revisions')->where('entry_id', THIRD_ENTRY)->count())->toBe(0)
        ->and(array_map(static fn (array $row): array => [$row['variant'], $row['rev_no']], $snapshots))->toBe([['shared', 2]])
        ->and(array_map(static fn (array $row): array => [$row['cms_stage'], $row['fixture_name'], $row['fixture_seats'], $row['fixture_price'], $row['fixture_kind']], $rows))
        ->toBe([['released', 'Harbour songs at dusk', 140, '250.00', 'fixture_concert']])
        ->and(json_decode(is_string($rows[0]['fixture_venue'] ?? null) ? $rows[0]['fixture_venue'] : '', true))
        ->toEqual(['fixture_venue_address' => ['fixture_venue_postcode' => '8000', 'fixture_venue_street' => 'Kajen 1'], 'fixture_venue_name' => 'The harbour hall'])
        ->and(StorageTables::superuser()->table('changesets')->whereIn('command', ['entry.create', 'entry.revise', 'placement.create', 'entry.publish'])->count())->toBe(4);

    $first = thirdTypeResolve();
    $second = thirdTypeResolve();

    $first->assertOk()
        ->assertHeader(DeliveryOutput::SOURCE, 'miss')
        ->assertHeader(DeliveryOutput::SURROGATE_KEY, 'e-'.THIRD_ENTRY.' n-'.$structure->northSection->id->toString())
        ->assertJsonPath('meta.type', THIRD_TYPE)
        ->assertJsonPath('meta.canonical_url', 'https://north.example/nyheder/harbour-concert')
        ->assertJsonPath('data.cms_id', THIRD_ENTRY)
        ->assertJsonPath('data.fixture_name', 'Harbour songs at dusk')
        ->assertJsonPath('data.fixture_seats', 140)
        ->assertJsonPath('data.fixture_price', '250.00')
        ->assertJsonPath('data.fixture_starts_at', '2026-04-01T19:30:00.000000Z')
        ->assertJsonPath('data.fixture_programme.0.markDefs.0.href', 'https://example.org/choir')
        ->assertJsonPath('data.fixture_venue.fixture_venue_address.fixture_venue_street', 'Kajen 1')
        ->assertJsonMissingPath('data.fixture_organiser_email');
    $second->assertOk()->assertHeader(DeliveryOutput::SOURCE, 'hit');
    expect($second->getContent())->toBe($first->getContent());
});

it('invalidates the event\'s fragment once the runner has handled a revise, and resolves the revised fields', function (): void {
    $structure = thirdTypeStructure();
    $world = new PublishingWorld([$structure->north->root, $structure->south->root]);
    thirdTypeRunner($world);
    $entry = EntryId::fromString(THIRD_ENTRY);
    $placement = PlacementId::fromString(THIRD_PLACEMENT);

    thirdTypeCommitted(
        $world->createEntry($entry, THIRD_TYPE, thirdTypeFields('Harbour songs'), $structure->northSection, 'third-create'),
        $world->place($placement, $entry, $structure->northSection, $structure->north, 'harbour-concert', 'third-place'),
        $world->publish($entry, 1, null, $placement, 1, 'third-publish'),
    );
    thirdTypeResolve()->assertOk()->assertHeader(DeliveryOutput::SOURCE, 'miss')->assertJsonPath('data.fixture_name', 'Harbour songs');
    $fragments = app(FragmentStore::class);
    $cached = $fragments->fragmentsOf(DependencyKey::entry($entry));

    $revise = $world->revise($entry, 1, thirdTypeFields('Harbour songs, moved indoors', 80, '120.00'), 'third-revise');
    thirdTypeCommitted($revise);
    $revised = thirdTypeChangeset($revise);
    $stillServed = thirdTypeResolve();

    $world->clock->advance(new DateInterval('PT2S'));
    thirdTypeRunUntilOrigin($revised);
    $cdn = app(CdnDriver::class);

    expect($cached)->toHaveCount(1)
        ->and($revise->receipt->projections)->toEqual([ProjectionStatus::pending(new ProjectionName(InvalidateFragments::PROJECTION))])
        ->and($fragments->fragmentsOf(DependencyKey::entry($entry)))->toBe([])
        ->and($cdn instanceof FakeCdnDriver ? $cdn->purgedWith(DependencyKey::entry($entry)) : null)->not->toBeNull();
    $stillServed->assertOk()->assertHeader(DeliveryOutput::SOURCE, 'hit')->assertJsonPath('data.fixture_name', 'Harbour songs');
    thirdTypeResolve()->assertOk()
        ->assertHeader(DeliveryOutput::SOURCE, 'miss')
        ->assertJsonPath('data.fixture_name', 'Harbour songs, moved indoors')
        ->assertJsonPath('data.fixture_seats', 80)
        ->assertJsonPath('data.fixture_price', '120.00');
});

it('creates, revises, places and publishes an event through cms:run as the configured service actor, and resolves it', function (): void {
    $structure = thirdTypeStructure();
    $clock = new PublishingWorld([$structure->north->root])->clock;
    app()->instance(Clock::class, $clock);
    app()->instance(CommandAuthorizer::class, new FakeCommandAuthorizer);
    app()->instance(IdGenerator::class, new FakeIdGenerator(seed: 5001, clock: $clock));

    $identity = PostgresIdentity::at($clock);
    $service = $identity->addActor(ActorClass::Service)->id;
    $credential = $identity->issue(new ServiceCredentialSpec($service, IssuerKind::Service, ClassificationAccess::Internal, $clock->now()->modify('+1 day')));
    $access = new PostgresAccessFixtures(app(DatabaseManager::class), $clock, new FakeIdGenerator(seed: 5002, clock: $clock));
    $commands = array_map(static fn (string $name): CommandName => new CommandName($name), ['entry.create', 'entry.revise', 'placement.create', 'entry.publish']);
    $access->grant($service, $access->role('third_writer', ClassificationAccess::Internal, $commands), $structure->north->root->id);
    config()->set(CliCredential::CONFIG_KEY, $credential->reveal());

    $fields = [
        'fixture_kind' => 'fixture_lecture',
        'fixture_name' => 'On tides',
        'fixture_seats' => 60,
        'fixture_starts_at' => '2026-04-02T18:00:00Z',
        'fixture_venue' => ['fixture_venue_address' => ['fixture_venue_street' => 'Kajen 2'], 'fixture_venue_name' => 'The library'],
    ];
    $run = static function (string $name, array $document, string $key): array {
        $kernel = app(Kernel::class);
        $status = $kernel->call('cms:run', ['name' => $name, 'version' => '1', 'document' => json_encode($document, JSON_THROW_ON_ERROR), '--idempotency-key' => $key, '--json' => true]);
        $output = json_decode(trim($kernel->output()), true, 64, JSON_THROW_ON_ERROR);

        return [$status, is_array($output) ? $output : throw new RuntimeException('cms:run printed no JSON object.')];
    };
    $answers = [
        $run('entry.create', ['entry' => THIRD_ENTRY, 'fields' => $fields, 'home' => $structure->northSection->id->toString(), 'type' => EntryWorld::type(THIRD_TYPE)->id->toString()], 'third-cli-create'),
        $run('entry.revise', ['entry' => THIRD_ENTRY, 'fields' => [...$fields, 'fixture_name' => 'On tides and moons'], 'version' => 1], 'third-cli-revise'),
        $run('placement.create', ['entry' => THIRD_ENTRY, 'node' => $structure->northSection->id->toString(), 'placement' => THIRD_PLACEMENT, 'site' => $structure->north->id->toString(), 'slugs' => [['locale' => 'da', 'slug' => 'tides']]], 'third-cli-place'),
        $run('entry.publish', ['entry' => THIRD_ENTRY, 'locale' => 'da', 'placement' => THIRD_PLACEMENT, 'placement_version' => 1, 'revision' => null, 'version' => 2], 'third-cli-publish'),
    ];

    expect(array_map(static fn (array $answer): array => [$answer[0], $answer[1]['outcome'] ?? $answer[1]], $answers))
        ->toBe([[0, 'committed'], [0, 'committed'], [0, 'committed'], [0, 'committed']]);
    thirdTypeResolve('tides')->assertOk()
        ->assertJsonPath('data.fixture_name', 'On tides and moons')
        ->assertJsonPath('data.fixture_kind', 'fixture_lecture')
        ->assertJsonPath('data.fixture_venue.fixture_venue_address.fixture_venue_street', 'Kajen 2');
});

it('rebuilds the event type table from the head snapshots with the same rows', function (): void {
    EntryWorld::seed();
    $world = new EntryWorld;
    $rebuild = new RebuildWorld($world, chunkSize: 2);
    $type = EntryWorld::type(THIRD_TYPE);
    $results = [];

    foreach (range(1, 5) as $number) {
        $results[] = $world->create($type->id, thirdTypeFields('Event '.$number, 10 * $number), 'c'.$number, RebuildWorld::entry($number));
    }

    foreach ([2, 4] as $number) {
        $results[] = $world->revise(1, thirdTypeFields('Event '.$number.', moved', 5 * $number, '99.50'), 'v'.$number, RebuildWorld::entry($number));
    }

    thirdTypeCommitted(...$results);
    $release = $world->release(2, 2, 'r2', RebuildWorld::entry(2));
    $before = RebuildWorld::rows(THIRD_TYPE);

    RebuildWorld::truncate(THIRD_TYPE);
    $emptied = RebuildWorld::rows(THIRD_TYPE);
    $report = $rebuild->rebuild(THIRD_TYPE);

    expect($release->outcome())->toBe(Outcome::Rejected)
        ->and(array_map(static fn (CatalogError $error): string => $error->code->value, $release->errors))->toBe(['type_not_releasable'])
        ->and($before)->toHaveCount(5)
        ->and(array_map(static fn (array $row): mixed => $row['fixture_name'] ?? null, $before))->toBe(['Event 1', 'Event 2, moved', 'Event 3', 'Event 4, moved', 'Event 5'])
        ->and(StorageTables::superuser()->table('head_snapshots')->orderBy('entry_id')->pluck('rev_no')->all())->toBe([1, 2, 1, 2, 1])
        ->and(StorageTables::superuser()->table('revisions')->count())->toBe(0)
        ->and($emptied)->toBe([])
        ->and(RebuildWorld::rows(THIRD_TYPE))->toBe($before)
        ->and($report->operation->state)->toBe(OperationState::Completed)
        ->and(array_map(static fn (ChunkResult $chunk): int => $chunk->entries, $report->chunks))->toBe([2, 2, 1]);
});

it('keeps the surface contract of every write action the registry exposes with the third type installed', function (SurfaceContractCase $case): void {
    expect(EntryWorld::type(THIRD_TYPE)->name->value)->toBe(THIRD_TYPE);

    $test = TestSuite::getInstance()->test;
    $case->verify($test instanceof TestCase ? $test : throw new AssertionFailedError('The surface contract runs in the workbench\'s test case.'));
})->with(static fn (): array => SurfaceContractCases::of(SurfaceContractCases::booted(), SurfaceProfiles::all()));
