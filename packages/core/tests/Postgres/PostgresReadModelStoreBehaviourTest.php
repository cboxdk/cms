<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Schema\History;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Access\Infrastructure\ActorContext;
use Cbox\Cms\Core\ReadModels\Adapter\PostgresReadModelStore;
use Cbox\Cms\Core\ReadModels\Domain\EntryRange;
use Cbox\Cms\Core\ReadModels\Domain\ReadModelStore;
use Cbox\Cms\Core\ReadModels\Domain\RebuildRefused;
use Cbox\Cms\Core\Tests\Entries\EntryFields;
use Cbox\Cms\Core\Tests\Entries\EntryWorld;
use Cbox\Cms\Core\Tests\ReadModels\ReadModelStoreBehaviour;
use Cbox\Cms\Core\Tests\ReadModels\RebuildWorld;
use Cbox\Cms\Testkit\Postgres\IndependentConnections;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\Test;

/**
 * ReadModelStoreBehaviour against the container's ReadModelStore, PostgresReadModelStore on the
 * default connection, as the app role under a service actor's context on real Postgres, with
 * entries written through the real command pipeline, and what it adds: row level security keeps
 * the entries the context does not reach out of the plan and the rebuild, a refused chunk leaves
 * the table as it was, a rebuild removes a row its heads do not give, it waits for a head another
 * transaction holds, Postgres ends its transaction at the 2-second budget, and it refuses a connection already in a transaction.
 */
final class PostgresReadModelStoreBehaviourTest extends TestCase
{
    use ReadModelStoreBehaviour;
    use RealPostgres;

    /** A section beside HOME, below the root. */
    private const string ELSEWHERE = '0192a0c0-0000-7000-8000-0000000001b1';

    private ?EntryWorld $world = null;

    private ?RebuildWorld $rebuild = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        EntryWorld::seed();
        $this->world = new EntryWorld;
        $this->rebuild = new RebuildWorld($this->world);
    }

    #[Override]
    protected function tearDown(): void
    {
        EntryWorld::cleanUp();

        parent::tearDown();
    }

    #[Override]
    protected function readModelStore(): ReadModelStore
    {
        return app(ReadModelStore::class);
    }

    #[Override]
    protected function rebuildAccess(): AccessContext
    {
        return $this->rebuilds()->access();
    }

    #[Override]
    protected function addEntries(TypeDefinition $type, int ...$numbers): void
    {
        foreach ($numbers as $number) {
            $this->create($type, $number);
        }
    }

    #[Override]
    protected function atOtherVersion(TypeDefinition $type, int $number): void
    {
        [$table, $column] = $type->capabilities->history === History::Full ? ['revisions', 'entry_id'] : ['head_snapshots', 'entry_id'];

        StorageTables::superuser()->table($table)->where($column, RebuildWorld::entry($number)->toString())->update(['schema_version' => $type->version + 1]);
    }

    #[Test]
    public function the_container_binds_the_postgres_store(): void
    {
        self::assertInstanceOf(PostgresReadModelStore::class, app(ReadModelStore::class));
    }

    #[Test]
    public function the_entries_the_context_does_not_reach_are_neither_planned_nor_rebuilt(): void
    {
        $type = EntryWorld::type(EntryWorld::MEASUREMENT);
        StorageTables::superuser()->table('nodes')->insert(StorageTables::node(self::ELSEWHERE, EntryWorld::ROOT, StorageTables::label(EntryWorld::ROOT)));
        $home = new RebuildWorld($this->worlds(), granted: EntryWorld::home(), seed: 4747)->access();
        $this->create($type, 1);
        $this->create($type, 2, NodeId::fromString(self::ELSEWHERE));
        $this->create($type, 3);
        $before = RebuildWorld::rows(EntryWorld::MEASUREMENT);
        StorageTables::superuser()->table('app__fixture_measurement')->update(['fixture_station' => 'stale']);

        $plan = $this->readModelStore()->plan($type, $home, 10);
        $result = $this->readModelStore()->rebuild($type, $home, new EntryRange(RebuildWorld::entry(1), RebuildWorld::entry(3)));
        $after = RebuildWorld::rows(EntryWorld::MEASUREMENT);

        self::assertEquals([new EntryRange(RebuildWorld::entry(1), RebuildWorld::entry(3))], $plan);
        self::assertSame(2, $result->entries);
        self::assertSame([$before[0], $before[2]], [$after[0], $after[2]]);
        self::assertSame('stale', $after[1]['fixture_station'] ?? null);
    }

    #[Test]
    public function a_refused_chunk_leaves_the_table_as_it_was(): void
    {
        $type = EntryWorld::type(EntryWorld::MEASUREMENT);
        $this->addEntries($type, 1, 2);
        StorageTables::superuser()->table('app__fixture_measurement')->update(['fixture_station' => 'stale']);
        $stale = RebuildWorld::rows(EntryWorld::MEASUREMENT);
        $this->atOtherVersion($type, 2);

        try {
            $this->readModelStore()->rebuild($type, $this->rebuildAccess(), new EntryRange(RebuildWorld::entry(1), RebuildWorld::entry(2)));
            self::fail('The rebuild should have refused the head snapshot at another schema version.');
        } catch (RebuildRefused $refused) {
            self::assertSame(RebuildRefused::CODE_SCHEMA_VERSION, $refused->errorCode);
        }

        self::assertSame($stale, RebuildWorld::rows(EntryWorld::MEASUREMENT));
        self::assertSame(0, DB::connection()->transactionLevel());
    }

    #[Test]
    public function a_rebuild_removes_a_row_the_heads_do_not_give_and_writes_the_ones_they_do(): void
    {
        $type = EntryWorld::type(EntryWorld::ARTICLE);
        $this->create($type, 1);
        self::assertSame(Outcome::Committed, $this->worlds()->release(1, 1, 'release-1', RebuildWorld::entry(1))->outcome());
        $released = RebuildWorld::rows(EntryWorld::ARTICLE);
        $draft = $released[0];
        $draft['cms_stage'] = 'draft';
        $draft['fixture_title'] = 'A draft the head does not have';
        StorageTables::superuser()->table('app__fixture_article')->insert($draft);
        StorageTables::superuser()->table('app__fixture_article')->where('cms_stage', 'released')->update(['fixture_reading_minutes' => 99]);

        $this->readModelStore()->rebuild($type, $this->rebuildAccess(), new EntryRange(RebuildWorld::entry(1), RebuildWorld::entry(1)));

        self::assertSame($released, RebuildWorld::rows(EntryWorld::ARTICLE));
    }

    #[Test]
    public function a_rebuild_waits_for_a_head_another_transaction_holds_and_postgres_ends_it_at_the_budget(): void
    {
        $type = EntryWorld::type(EntryWorld::MEASUREMENT);
        $this->create($type, 1);
        $before = RebuildWorld::rows(EntryWorld::MEASUREMENT);
        [$holder] = app(IndependentConnections::class)->open(1);
        $holder->beginTransaction();
        new ActorContext(app(ConnectionResolverInterface::class), $holder->getName())->set($this->worlds()->access());
        $holder->table('variant_heads')->where('entry_id', RebuildWorld::entry(1)->toString())->lockForUpdate()->value('version');
        StorageTables::superuser()->table('app__fixture_measurement')->update(['fixture_station' => 'stale']);
        $started = hrtime(true);

        try {
            $this->readModelStore()->rebuild($type, $this->rebuildAccess(), new EntryRange(RebuildWorld::entry(1), RebuildWorld::entry(1)));
            self::fail('The rebuild should have waited for the held head until Postgres ended its transaction.');
        } catch (QueryException $ended) {
            self::assertStringContainsString('transaction timeout', $ended->getMessage());
        } finally {
            $holder->rollBack();
        }

        $waited = intdiv(hrtime(true) - $started, 1_000_000);
        DB::purge();

        self::assertGreaterThanOrEqual(PostgresReadModelStore::TRANSACTION_MILLISECONDS, $waited);
        self::assertSame('stale', RebuildWorld::rows(EntryWorld::MEASUREMENT)[0]['fixture_station'] ?? null);

        $this->readModelStore()->rebuild($type, $this->rebuildAccess(), new EntryRange(RebuildWorld::entry(1), RebuildWorld::entry(1)));

        self::assertSame($before, RebuildWorld::rows(EntryWorld::MEASUREMENT));
    }

    #[Test]
    public function it_refuses_a_connection_already_in_a_transaction(): void
    {
        $type = EntryWorld::type(EntryWorld::MEASUREMENT);
        DB::connection()->beginTransaction();

        try {
            $this->readModelStore()->plan($type, $this->rebuildAccess(), 10);
            self::fail('The plan should have refused the open transaction.');
        } catch (LogicException $refused) {
            self::assertStringContainsString('already in a transaction', $refused->getMessage());
        } finally {
            DB::connection()->rollBack();
        }
    }

    private function create(TypeDefinition $type, int $number, ?NodeId $home = null): void
    {
        $fields = $type->name->value === EntryWorld::ARTICLE ? EntryFields::article('Article '.$number) : EntryFields::measurement(sprintf('%d.125', $number), 'station-'.$number);
        $result = $this->worlds()->create($type->id, $fields, 'create-'.$number, RebuildWorld::entry($number), $home);

        self::assertSame(Outcome::Committed, $result->outcome());
    }

    private function worlds(): EntryWorld
    {
        return $this->world ?? throw new LogicException('setUp() makes the entry world.');
    }

    private function rebuilds(): RebuildWorld
    {
        return $this->rebuild ?? throw new LogicException('setUp() makes the rebuild world.');
    }
}
