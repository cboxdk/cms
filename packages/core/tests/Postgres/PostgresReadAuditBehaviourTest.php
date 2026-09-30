<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Core\Reads\Domain\Dto\ReadAuditRecord;
use Cbox\Cms\Core\Reads\Domain\ReadAudit;
use Cbox\Cms\Core\Tests\Reads\ReadAuditBehaviour;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Override;

/**
 * ReadAuditBehaviour against PostgresReadAudit on the default connection, as the app role on real
 * Postgres, in a transaction with the actor context of the record's actor.
 */
final class PostgresReadAuditBehaviourTest extends TestCase
{
    use ReadAuditBehaviour;
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
    protected function readAudit(): ReadAudit
    {
        return $this->tables()->audit();
    }

    #[Override]
    protected function auditRecord(string ...$entries): ReadAuditRecord
    {
        return $this->tables()->record(...$entries);
    }

    #[Override]
    protected function begin(): void
    {
        app(DatabaseManager::class)->connection()->beginTransaction();
        $this->tables()->context();
    }

    #[Override]
    protected function commit(): void
    {
        app(DatabaseManager::class)->connection()->commit();
    }

    #[Override]
    protected function rollBack(): void
    {
        app(DatabaseManager::class)->connection()->rollBack();
    }

    #[Override]
    protected function auditedEntries(): array
    {
        return array_map(static fn (string $row): string => explode(' | ', $row)[1], $this->tables()->rows());
    }

    private function tables(): ReadAuditTables
    {
        return $this->tables ??= ReadAuditTables::at();
    }
}
