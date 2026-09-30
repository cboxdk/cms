<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Seeding;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Seeding\Actions\SeedDataset;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedReport;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedRequest;
use Cbox\Cms\Core\Seeding\Domain\SeedProfile;
use Cbox\Cms\Core\Tests\Postgres\StorageTables;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Access\Adapter\PostgresAccessFixtures;
use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Adapter\PostgresStructureFixtures;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Domain\Dto\StructureNode;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;

/**
 * A structure to seed into on Postgres, and the seeder run through the container (GUARDRAILS 4.3):
 * the Clock and the IdGenerator are a FakeClock at NOW and a seeded FakeIdGenerator, whose day the
 * partitions cover, a site with SECTIONS sections below its root, and a service actor with a role
 * granted on the site's root, which cbox-cms.seeding.service_actor names. The same world is built
 * with the same ids every time, so two runs in one test can be compared after the tables are
 * emptied.
 */
final readonly class SeedWorld
{
    public const string NOW = '2026-03-10T12:00:00.000000+00:00';

    public const int SECTIONS = 12;

    /** @var list<string> the kernel's tables a seed run writes to; the type tables come from the TypeCatalog */
    public const array TABLES = [
        'audit', 'changesets', 'entries', 'events', 'head_snapshots', 'idempotency_keys', 'receipts',
        'release_log', 'revision_payloads', 'revisions', 'variant_heads',
    ];

    public ActorId $actor;

    public StructureNode $root;

    public function __construct(ClassificationAccess $ceiling = ClassificationAccess::Internal)
    {
        $clock = new FakeClock(new DateTimeImmutable(self::NOW));
        app()->instance(Clock::class, $clock);
        app()->instance(IdGenerator::class, new FakeIdGenerator(seed: 4747, clock: $clock));
        app(PartitionFixtures::class)->coverClock($clock, new DateInterval('P1D'));

        $connections = app(ConnectionResolverInterface::class);
        $this->actor = new PostgresIdentitySeeder($connections, $clock, new FakeIdGenerator(seed: 4701, clock: $clock))->addActor(ActorClass::Service)->id;

        $structure = new PostgresStructureFixtures($connections, $clock, new FakeIdGenerator(seed: 4702, clock: $clock));
        $site = $structure->site('seeded', [new Locale('en')]);
        $this->root = $site->root;

        for ($section = 0; $section < self::SECTIONS; $section++) {
            $structure->node($site->root);
        }

        $access = new PostgresAccessFixtures(app(DatabaseManager::class), $clock, new FakeIdGenerator(seed: 4703, clock: $clock));
        $access->grant($this->actor, $access->role('seeder', $ceiling, [new CommandName('seed.entries')]), $site->root->id);

        config(['cbox-cms.seeding.service_actor' => $this->actor->toString()]);
    }

    public function seed(SeedProfile $profile, int $seed, int $entries): SeedReport
    {
        return app(SeedDataset::class)->run(new SeedRequest($profile, $seed, $entries));
    }

    /**
     * The table of every type in the installation's TypeCatalog, sorted, so a type added as a
     * schema file is counted without a change here (GUARDRAILS 2.4).
     *
     * @return list<string>
     */
    public static function typeTables(): array
    {
        $tables = array_map(static fn (TypeDefinition $type): string => $type->name->table(), app(TypeCatalog::class)->all());
        sort($tables, SORT_STRING);

        return $tables;
    }

    /**
     * The rows of each table the seeder writes, the kernel's and every type table, read as the
     * superuser, past row level security.
     *
     * @return array<string, int>
     */
    public static function rows(): array
    {
        $rows = [];

        foreach ([...self::TABLES, ...self::typeTables()] as $table) {
            $rows[$table] = StorageTables::superuser()->table($table)->count();
        }

        return $rows;
    }

    /**
     * Every entry's id, type and home node, sorted by id.
     *
     * @return list<string>
     */
    public static function entries(): array
    {
        $entries = [];

        foreach (StorageTables::superuser()->table('entries')->orderBy('id')->get(['id', 'type_id', 'home_node_id']) as $row) {
            $entries[] = implode(' ', array_map(static fn (mixed $value): string => is_string($value) ? $value : '', get_object_vars($row)));
        }

        return $entries;
    }

    public static function cleanUp(): void
    {
        DB::purge(StorageTables::SUPERUSER);
    }
}
