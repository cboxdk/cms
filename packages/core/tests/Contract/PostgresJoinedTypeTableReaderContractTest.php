<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Contract;

use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\TypeTables\TypeTableReader;
use Cbox\Cms\Core\Tests\TypeTables\PostgresTypeTables;
use Cbox\Cms\Core\TypeTables\Adapter\PostgresTypeTableReader;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\TypeTables\TypeTableReaderContract;
use Cbox\Cms\Testkit\TypeTables\TypeTableSeed;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Database\ConnectionResolverInterface;
use Override;

/**
 * The shared TypeTableReader contract suite against the core's Postgres adapter, as the app role
 * with the actor context set and row level security on, over the suite's type table, which the
 * test creates as the owner role and seeds as the superuser (GUARDRAILS 9: the same suite against
 * the fake and the real adapter). The reader joins nodes and tests each row's home node path against the regions, as it does when the regions reach more nodes than the limit.
 */
final class PostgresJoinedTypeTableReaderContractTest extends TestCase
{
    use RealPostgres;
    use TypeTableReaderContract;

    #[Override]
    protected function tearDown(): void
    {
        PostgresTypeTables::drop(self::suiteType());

        parent::tearDown();
    }

    #[Override]
    protected function typeTableReader(TypeDefinition $type, TypeTableSeed ...$rows): TypeTableReader
    {
        PostgresTypeTables::create($type);
        PostgresTypeTables::seed($type, ...$rows);

        return PostgresTypeTables::inContext(new PostgresTypeTableReader(app(ConnectionResolverInterface::class), new FakeTypeCatalog($type), regionNodeLimit: 0));
    }
}
