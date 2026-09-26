<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Core\Partitions\Domain\PartitionMaintenance;
use Cbox\Cms\Core\Partitions\Domain\PartitionPolicy;
use Cbox\Cms\Core\Partitions\Infrastructure\PostgresPartitionManager;
use Cbox\Cms\Core\Tests\Partitions\PartitionMaintenanceBehaviour;
use Cbox\Cms\Testkit\Postgres\IndependentConnections;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\PostgresConnection;
use LogicException;
use Override;

/**
 * PartitionMaintenanceBehaviour against PostgresPartitionManager on real Postgres, as the owner
 * role, with the scratch tables of PartitionScratch. The locks are held by an independent owner
 * connection: the advisory lock of a run, or SHARE on a table in an open transaction, which
 * conflicts with both ATTACH PARTITION and the wait before DETACH PARTITION CONCURRENTLY.
 */
final class PostgresPartitionMaintenanceBehaviourTest extends TestCase
{
    use PartitionMaintenanceBehaviour;
    use RealPostgres;

    private ?PostgresConnection $locker = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        PartitionScratch::create();
    }

    #[Override]
    protected function tearDown(): void
    {
        app(IndependentConnections::class)->closeAll();
        $this->locker = null;
        PartitionScratch::drop();

        parent::tearDown();
    }

    #[Override]
    protected function partitionMaintenance(PartitionPolicy $policy): PartitionMaintenance
    {
        return new PostgresPartitionManager(app(ConnectionResolverInterface::class), $policy);
    }

    #[Override]
    protected function ownerConnection(): string
    {
        return 'pgsql_owner';
    }

    #[Override]
    protected function appConnection(): string
    {
        return app(ConnectionResolverInterface::class)->getDefaultConnection();
    }

    #[Override]
    protected function ownerRole(): string
    {
        return 'cms_owner';
    }

    #[Override]
    protected function holdRunLock(): void
    {
        $this->locker()->select('select pg_advisory_lock(?)', [PostgresPartitionManager::ADVISORY_LOCK]);
    }

    #[Override]
    protected function releaseRunLock(): void
    {
        $this->locker()->select('select pg_advisory_unlock(?)', [PostgresPartitionManager::ADVISORY_LOCK]);
    }

    #[Override]
    protected function lockTable(string $table): void
    {
        $this->locker()->beginTransaction();
        $this->locker()->statement(sprintf('lock table %s in share mode', $table));
    }

    #[Override]
    protected function unlockTable(string $table): void
    {
        $this->locker()->rollBack();
    }

    #[Override]
    protected function partitionsOf(string $table): array
    {
        return PartitionScratch::partitions($table);
    }

    private function locker(): PostgresConnection
    {
        if (! $this->locker instanceof PostgresConnection) {
            [$this->locker] = app(IndependentConnections::class)->open(1, $this->ownerConnection());
        }

        return $this->locker ?? throw new LogicException('No independent connection was opened.');
    }
}
