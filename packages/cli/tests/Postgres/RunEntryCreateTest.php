<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Postgres;

use Cbox\Cms\Cli\Boundary\CliCredential;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Tests\Entries\EntryWorld;
use Cbox\Cms\Core\Tests\Identity\PostgresIdentity;
use Cbox\Cms\Core\Tests\Postgres\StorageTables;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Access\Adapter\PostgresAccessFixtures;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Adapter\PostgresStructureFixtures;
use Cbox\Cms\Testkit\Identity\ServiceCredentialSpec;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/*
 * The CLI surface with the kernel's own command (GUARDRAILS 2.1, 2.2, M1): `cms:run entry.create 1
 * <document>` reads the command with the generated codec CreateEntryCodecV1, which the core
 * registers under CommandCodecs::TAG, and runs it through the container's pipeline on Postgres as
 * the service actor of the configured credential, whose role may run entry.create on the site's
 * root. The entry is created for the workbench's fixture type app:fixture_article with its fields
 * as the document gives them, and a document that breaks the command's schema is refused before
 * anything runs.
 *
 * Everything is the container's, the kernel's CommandAuthorizer included, which allows entry.create
 * because the service actor's role names it and is granted on the site's root, and refuses
 * entry.revise, which no role of the actor names, and placement.create in a locale the grant of the
 * role that names it excludes.
 */

const RUN_CREATE_NOW = '2026-03-10T12:00:00.000000+00:00';

const RUN_CREATE_ENTRY = '0192a0c0-0000-7000-8000-0000000065e1';

afterEach(function (): void {
    EntryWorld::cleanUp();
});

/**
 * A site, with the locale da, below which the service actor of the configured CLI credential may
 * run entry.create, and any further roles given, each with its commands and its locales (null for
 * every locale) granted on the site's root, at a FakeClock whose day the partitions cover; the ids
 * of the site's root and the site.
 *
 * @param  list<array{string, list<string>, list<string>|null}>  $roles
 * @return array{string, string}
 */
function runCreateWorld(array $roles = []): array
{
    $clock = new FakeClock(new DateTimeImmutable(RUN_CREATE_NOW));
    app()->instance(Clock::class, $clock);
    app()->instance(IdGenerator::class, new FakeIdGenerator(seed: 6501, clock: $clock));
    app(PartitionFixtures::class)->coverClock($clock, new DateInterval('P1D'));

    $identity = PostgresIdentity::at($clock);
    $service = $identity->addActor(ActorClass::Service)->id;
    $credential = $identity->issue(new ServiceCredentialSpec($service, IssuerKind::Service, ClassificationAccess::Internal, $clock->now()->modify('+1 day')));

    $site = new PostgresStructureFixtures(app(ConnectionResolverInterface::class), $clock, new FakeIdGenerator(seed: 6502, clock: $clock))->site('cli', [new Locale('da')]);
    $access = new PostgresAccessFixtures(app(DatabaseManager::class), $clock, new FakeIdGenerator(seed: 6503, clock: $clock));
    $access->grant($service, $access->role('cli_writer', ClassificationAccess::Internal, [new CommandName('entry.create')]), $site->root->id);

    foreach ($roles as [$handle, $commands, $locales]) {
        $role = $access->role($handle, ClassificationAccess::Internal, array_map(static fn (string $name): CommandName => new CommandName($name), $commands));
        $access->grant($service, $role, $site->root->id, locales: $locales === null ? null : array_map(static fn (string $locale): Locale => new Locale($locale), $locales));
    }

    config()->set(CliCredential::CONFIG_KEY, $credential->reveal());

    return [$site->root->id->toString(), $site->id->toString()];
}

/**
 * Runs cms:run <name> 1 with the document and --json, entry.create unless another is named; the
 * exit code and the JSON it printed.
 *
 * @return array{int, array<array-key, mixed>}
 */
function runCreate(string $document, string $key, string $name = 'entry.create'): array
{
    $kernel = app(Kernel::class);
    $status = $kernel->call('cms:run', ['name' => $name, 'version' => '1', 'document' => $document, '--idempotency-key' => $key, '--json' => true]);
    $output = json_decode(trim($kernel->output()), true, 64, JSON_THROW_ON_ERROR);

    return [$status, is_array($output) ? $output : throw new RuntimeException('cms:run printed no JSON object.')];
}

it('creates an entry of a workbench fixture type through cms:run entry.create 1 as the configured service actor', function (): void {
    [$home] = runCreateWorld();
    $type = EntryWorld::type(EntryWorld::ARTICLE);
    $document = json_encode([
        'entry' => RUN_CREATE_ENTRY,
        'fields' => ['fixture_featured' => true, 'fixture_reading_minutes' => 4, 'fixture_title' => 'The harbour opens'],
        'home' => $home,
        'type' => $type->id->toString(),
    ], JSON_THROW_ON_ERROR);

    [$status, $receipt] = runCreate($document, 'cli-entry-create');
    $entry = StorageTables::superuser()->table('entries')->where('id', RUN_CREATE_ENTRY)->first(['type_id', 'home_node_id', 'version']);
    $row = StorageTables::superuser()->table('app__fixture_article')->where('cms_entry_id', RUN_CREATE_ENTRY)->first(['fixture_title', 'fixture_featured', 'fixture_reading_minutes']);

    expect($status)->toBe(0, (string) json_encode($receipt))
        ->and($receipt['outcome'] ?? null)->toBe('committed')
        ->and($receipt['changeset_id'] ?? null)->toBeString()
        ->and($entry === null ? null : [$entry->type_id, $entry->home_node_id, $entry->version])->toBe([$type->id->toString(), $home, 1])
        ->and($row === null ? null : [$row->fixture_title, $row->fixture_featured, $row->fixture_reading_minutes])->toBe(['The harbour opens', true, 4]);
});

it('refuses a document that breaks the schema of entry.create before it runs, with the codec\'s path', function (): void {
    [$home] = runCreateWorld();

    [$status, $problem] = runCreate(json_encode(['entry' => RUN_CREATE_ENTRY, 'fields' => ['Title' => 'x'], 'home' => $home, 'type' => EntryWorld::type(EntryWorld::ARTICLE)->id->toString()], JSON_THROW_ON_ERROR), 'cli-entry-refused');

    expect($status)->not->toBe(0)
        ->and($problem['code'] ?? null)->toBe('json_invalid')
        ->and(json_encode($problem))->toContain('fields')
        ->and(StorageTables::superuser()->table('entries')->where('id', RUN_CREATE_ENTRY)->count())->toBe(0);
});

/**
 * entry.create of the workbench's article below the site's root, which the world's service actor
 * may run.
 */
function runCreatedEntry(string $home): void
{
    $document = json_encode([
        'entry' => RUN_CREATE_ENTRY,
        'fields' => ['fixture_featured' => true, 'fixture_reading_minutes' => 4, 'fixture_title' => 'The harbour opens'],
        'home' => $home,
        'type' => EntryWorld::type(EntryWorld::ARTICLE)->id->toString(),
    ], JSON_THROW_ON_ERROR);

    [$status, $receipt] = runCreate($document, 'cli-entry-create');

    expect([$status, $receipt['outcome'] ?? null])->toBe([0, 'committed'], (string) json_encode($receipt));
}

it('refuses through cms:run a command no role of the service actor names, on the node its other role reaches', function (): void {
    [$home] = runCreateWorld();
    runCreatedEntry($home);

    [$status, $problem] = runCreate(json_encode(['entry' => RUN_CREATE_ENTRY, 'fields' => ['fixture_featured' => true, 'fixture_reading_minutes' => 4, 'fixture_title' => 'Revised'], 'version' => 1], JSON_THROW_ON_ERROR), 'cli-entry-revise', 'entry.revise');
    $entry = StorageTables::superuser()->table('entries')->where('id', RUN_CREATE_ENTRY)->first(['version']);

    expect($status)->toBe(77)
        ->and($problem['code'] ?? null)->toBe('unauthorized')
        ->and(json_encode($problem))->toContain('entry.revise')
        ->and($entry?->version)->toBe(1);
});

it('refuses through cms:run a placement in a locale the grant of the role that may place excludes', function (): void {
    [$home, $site] = runCreateWorld([['cli_placer', ['placement.create'], ['en']]]);
    runCreatedEntry($home);
    $placement = '0192a0c0-0000-7000-8000-0000000065c1';

    [$status, $problem] = runCreate(json_encode([
        'entry' => RUN_CREATE_ENTRY,
        'node' => $home,
        'placement' => $placement,
        'site' => $site,
        'slugs' => [['locale' => 'da', 'slug' => 'harbour']],
    ], JSON_THROW_ON_ERROR), 'cli-placement-create', 'placement.create');

    expect($status)->toBe(77)
        ->and($problem['code'] ?? null)->toBe('unauthorized')
        ->and(json_encode($problem))->toContain('locale da')
        ->and(StorageTables::superuser()->table('placements')->where('id', $placement)->count())->toBe(0);
});
