<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Partitions;

use Cbox\Cms\Core\Partitions\Domain\PartitionMaintenance;
use Cbox\Cms\Core\Partitions\Domain\PartitionPolicy;
use Cbox\Cms\Core\Tests\Partitions\Fakes\FakePartitionMaintenance;
use LogicException;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * PartitionMaintenanceBehaviour against the fake the partition action tests use. The Postgres
 * manager runs the same cases in PostgresPartitionMaintenanceBehaviourTest.
 */
final class FakePartitionMaintenanceBehaviourTest extends TestCase
{
    use PartitionMaintenanceBehaviour;

    private ?FakePartitionMaintenance $fake = null;

    #[Override]
    protected function partitionMaintenance(PartitionPolicy $policy): PartitionMaintenance
    {
        return $this->fake = new FakePartitionMaintenance($policy, $this->appConnection(), $this->ownerRole());
    }

    #[Override]
    protected function ownerConnection(): string
    {
        return 'pgsql_owner';
    }

    #[Override]
    protected function appConnection(): string
    {
        return 'pgsql';
    }

    #[Override]
    protected function ownerRole(): string
    {
        return 'cms_owner';
    }

    #[Override]
    protected function holdRunLock(): void
    {
        $this->fake()->holdRunLock();
    }

    #[Override]
    protected function releaseRunLock(): void
    {
        $this->fake()->releaseRunLock();
    }

    #[Override]
    protected function lockTable(string $table): void
    {
        $this->fake()->lockTable($table);
    }

    #[Override]
    protected function unlockTable(string $table): void
    {
        $this->fake()->unlockTable($table);
    }

    #[Override]
    protected function dropTable(string $table): void
    {
        $this->fake()->dropTable($table);
    }

    #[Override]
    protected function partitionsOf(string $table): array
    {
        return $this->fake()->partitions($table);
    }

    private function fake(): FakePartitionMaintenance
    {
        return $this->fake ?? throw new LogicException('The case asked for the fake before it made one.');
    }
}
