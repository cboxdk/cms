<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Core\Reads\Domain\QueryTransaction;
use Cbox\Cms\Core\Tests\Reads\QueryTransactionBehaviour;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Override;

/**
 * QueryTransactionBehaviour against the container's QueryTransaction, ConnectionQueryTransaction on
 * the default connection, as the app role on real Postgres, with the Postgres read audit on the
 * same connection under the actor context it needs.
 */
final class ConnectionQueryTransactionBehaviourTest extends TestCase
{
    use QueryTransactionBehaviour;
    use RealPostgres;

    private ?ReadAuditTables $tables = null;

    /**
     * Seeds the actor and covers the partitions before any transaction opens (ReadAuditTables).
     */
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->tables();
    }

    #[Override]
    protected function tearDown(): void
    {
        DB::purge(StorageTables::SUPERUSER);

        parent::tearDown();
    }

    #[Override]
    protected function queryTransaction(): QueryTransaction
    {
        return app(QueryTransaction::class);
    }

    #[Override]
    protected function recordInside(): void
    {
        $this->tables()->context();
        $this->tables()->audit()->record($this->tables()->record());
    }

    #[Override]
    protected function recorded(): int
    {
        return count($this->tables()->rows());
    }

    #[Override]
    protected function openOutside(): void
    {
        app(DatabaseManager::class)->connection()->beginTransaction();
    }

    #[Override]
    protected function closeOutside(): void
    {
        app(DatabaseManager::class)->connection()->rollBack();
    }

    private function tables(): ReadAuditTables
    {
        return $this->tables ??= ReadAuditTables::at();
    }
}
