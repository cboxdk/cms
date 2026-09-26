<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Partitions;

use Cbox\Cms\Core\Partitions\Domain\DdlStep;
use Cbox\Cms\Core\Partitions\Domain\Dto\PartitionChange;
use Cbox\Cms\Core\Partitions\Domain\Dto\PartitionRange;
use Cbox\Cms\Core\Partitions\Domain\Dto\PartitionReport;
use Cbox\Cms\Core\Partitions\Domain\Dto\TableRunway;
use Cbox\Cms\Core\Partitions\Domain\InvalidPartitionPolicy;
use Cbox\Cms\Core\Partitions\Domain\LockTimeout;
use Cbox\Cms\Core\Partitions\Domain\OwnerConnectionRequired;
use Cbox\Cms\Core\Partitions\Domain\PartitionChangeKind;
use Cbox\Cms\Core\Partitions\Domain\PartitionedTable;
use Cbox\Cms\Core\Partitions\Domain\PartitionInterval;
use Cbox\Cms\Core\Partitions\Domain\PartitionKey;
use Cbox\Cms\Core\Partitions\Domain\PartitionMaintenance;
use Cbox\Cms\Core\Partitions\Domain\PartitionPolicy;
use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

/**
 * What every PartitionMaintenance does (PRD 4, 4.2), run against PostgresPartitionManager on real
 * Postgres and against FakePartitionMaintenance, so the fake the action tests use cannot drift
 * from the manager (GUARDRAILS 9).
 *
 * The cases manage two tables: UUID_TABLE, range-partitioned on a UUIDv7 key, and TIME_TABLE, on
 * a timestamptz; both exist without partitions when a case starts. The policies use a short lock
 * timeout, so a busy lock gives up in a fraction of a second.
 */
trait PartitionMaintenanceBehaviour
{
    protected const string UUID_TABLE = 'partition_scratch';

    protected const string TIME_TABLE = 'partition_scratch_ts';

    /**
     * The implementation under test, keeping the tables of the policy.
     */
    abstract protected function partitionMaintenance(PartitionPolicy $policy): PartitionMaintenance;

    /** The connection name of the owner role, which runs the DDL. */
    abstract protected function ownerConnection(): string;

    /** The application's default connection, which maintenance refuses. */
    abstract protected function appConnection(): string;

    /** The database role the report names. */
    abstract protected function ownerRole(): string;

    /** Another run holds the maintenance lock until releaseRunLock(). */
    abstract protected function holdRunLock(): void;

    abstract protected function releaseRunLock(): void;

    /** Another session holds a SHARE lock on the table until unlockTable(). */
    abstract protected function lockTable(string $table): void;

    abstract protected function unlockTable(string $table): void;

    /**
     * The partitions attached to the table, by name.
     *
     * @return list<string>
     */
    abstract protected function partitionsOf(string $table): array;

    #[Test]
    public function maintain_creates_the_runway_ahead_of_now_and_a_second_run_changes_nothing(): void
    {
        $maintenance = $this->partitionMaintenance($this->policy([$this->dailyTable()], runwayDays: 3));
        $now = new DateTimeImmutable('2026-03-10T15:00:00.250000Z');

        $report = $maintenance->maintain($now);
        $expected = $this->daily('2026-03-10', 4);

        Assert::assertSame($this->ownerRole(), $report->role);
        Assert::assertSame($this->changes('created', $expected), $this->describe($report));
        Assert::assertSame($expected, $this->partitionsOf(self::UUID_TABLE));
        Assert::assertCount(1, $report->runways);
        Assert::assertSame(self::UUID_TABLE, $report->runways[0]->table);
        Assert::assertSame('2026-03-14T00:00:00+00:00', $report->runways[0]->coveredUntil?->format(DATE_ATOM));

        $second = $maintenance->maintain($now);

        Assert::assertSame([], $second->changes);
        Assert::assertSame($expected, $this->partitionsOf(self::UUID_TABLE));
        Assert::assertSame('2026-03-14T00:00:00+00:00', $second->runways[0]->coveredUntil?->format(DATE_ATOM));
    }

    #[Test]
    public function maintain_keeps_every_table_of_the_policy_in_policy_order_by_its_interval(): void
    {
        $monthly = new PartitionedTable(self::TIME_TABLE, PartitionKey::Timestamp, PartitionInterval::Month, null);
        $maintenance = $this->partitionMaintenance($this->policy([$monthly, $this->dailyTable()], runwayDays: 1));

        $report = $maintenance->maintain(new DateTimeImmutable('2026-01-31T08:00:00Z'));

        Assert::assertSame([
            'created '.self::TIME_TABLE.'_p202601',
            'created '.self::TIME_TABLE.'_p202602',
            'created '.self::UUID_TABLE.'_p20260131',
            'created '.self::UUID_TABLE.'_p20260201',
        ], $this->describe($report));
        Assert::assertSame([self::TIME_TABLE, self::UUID_TABLE], array_map(static fn (TableRunway $runway): string => $runway->table, $report->runways));
        Assert::assertSame('2026-03-01T00:00:00+00:00', $report->runways[0]->coveredUntil?->format(DATE_ATOM));
        Assert::assertSame('2026-02-02T00:00:00+00:00', $report->runways[1]->coveredUntil?->format(DATE_ATOM));
    }

    #[Test]
    public function cover_creates_exactly_the_partitions_of_the_range_and_removes_nothing(): void
    {
        $maintenance = $this->partitionMaintenance($this->policy([$this->dailyTable(retentionDays: 1)]));

        $report = $maintenance->cover($this->range('2026-01-01T12:00:00Z', '2026-01-03T00:00:00Z'));

        Assert::assertSame($this->changes('created', $this->daily('2026-01-01', 3)), $this->describe($report));
        Assert::assertSame('2026-01-04T00:00:00+00:00', $report->runways[0]->coveredUntil?->format(DATE_ATOM));

        $later = $maintenance->cover($this->range('2026-06-01T00:00:00Z', '2026-06-01T00:00:00Z'));

        Assert::assertSame($this->changes('created', ['partition_scratch_p20260601']), $this->describe($later));
        Assert::assertSame([...$this->daily('2026-01-01', 3), 'partition_scratch_p20260601'], $this->partitionsOf(self::UUID_TABLE));
        Assert::assertSame([], $maintenance->cover($this->range('2026-01-02T00:00:00Z', '2026-01-02T23:59:59Z'))->changes);
    }

    #[Test]
    public function cover_refuses_a_range_that_needs_too_many_partitions_and_creates_none(): void
    {
        $maintenance = $this->partitionMaintenance($this->policy([
            new PartitionedTable(self::TIME_TABLE, PartitionKey::Timestamp, PartitionInterval::Month, null),
            $this->dailyTable(),
        ]));

        $refused = $this->thrown(static fn (): PartitionReport => $maintenance->cover(new PartitionRange(
            new DateTimeImmutable('2020-01-01T00:00:00Z'),
            new DateTimeImmutable('2023-01-01T00:00:00Z'),
        )));

        Assert::assertInstanceOf(InvalidPartitionPolicy::class, $refused);
        Assert::assertStringContainsString('needs more than '.PartitionedTable::MAX_PARTITIONS_PER_CALL.' partitions of table "'.self::UUID_TABLE.'"', $refused->getMessage());
        Assert::assertSame([], $this->partitionsOf(self::TIME_TABLE));
        Assert::assertSame([], $this->partitionsOf(self::UUID_TABLE));
    }

    #[Test]
    public function maintain_detaches_and_drops_the_partitions_past_retention_one_at_a_time(): void
    {
        $maintenance = $this->partitionMaintenance($this->policy([$this->dailyTable(retentionDays: 7)], runwayDays: 1));
        $maintenance->cover($this->range('2026-01-01T00:00:00Z', '2026-01-14T00:00:00Z'));

        // At 2026-01-20 with 7 days of retention, a partition has expired when its span ended at
        // 2026-01-13 or before: 2026-01-12 has, 2026-01-13 has not.
        $report = $maintenance->maintain(new DateTimeImmutable('2026-01-20T00:00:00Z'));

        $retired = [];

        foreach ($this->daily('2026-01-01', 12) as $partition) {
            $retired[] = 'detached '.$partition;
            $retired[] = 'dropped '.$partition;
        }

        Assert::assertSame([...$this->changes('created', $this->daily('2026-01-20', 2)), ...$retired], $this->describe($report));
        Assert::assertSame([...$this->daily('2026-01-13', 2), ...$this->daily('2026-01-20', 2)], $this->partitionsOf(self::UUID_TABLE));
    }

    #[Test]
    public function a_table_without_retention_keeps_every_partition(): void
    {
        $maintenance = $this->partitionMaintenance($this->policy([$this->dailyTable()], runwayDays: 1));
        $maintenance->cover($this->range('2020-01-01T00:00:00Z', '2020-01-01T00:00:00Z'));

        $report = $maintenance->maintain(new DateTimeImmutable('2026-01-20T00:00:00Z'));

        Assert::assertSame($this->changes('created', $this->daily('2026-01-20', 2)), $this->describe($report));
        Assert::assertContains('partition_scratch_p20200101', $this->partitionsOf(self::UUID_TABLE));
    }

    #[Test]
    public function a_busy_run_lock_makes_the_run_give_up_at_the_lock_step_before_it_changes_anything(): void
    {
        $maintenance = $this->partitionMaintenance($this->policy([$this->dailyTable()], runwayDays: 1, attempts: 2));
        $now = new DateTimeImmutable('2026-01-01T00:00:00Z');
        $this->holdRunLock();

        try {
            $timeout = $this->thrown(static fn (): PartitionReport => $maintenance->maintain($now));
            $coverTimeout = $this->thrown(fn (): PartitionReport => $maintenance->cover($this->range('2025-12-01T00:00:00Z', '2025-12-01T00:00:00Z')));
        } finally {
            $this->releaseRunLock();
        }

        foreach ([$timeout, $coverTimeout] as $thrown) {
            Assert::assertInstanceOf(LockTimeout::class, $thrown);
            Assert::assertSame(DdlStep::Lock, $thrown->step);
            Assert::assertNull($thrown->table);
            Assert::assertNull($thrown->partition);
            Assert::assertSame(2, $thrown->attempts);
            Assert::assertStringStartsWith('['.LockTimeout::CODE.']', $thrown->getMessage());
        }

        Assert::assertSame([], $this->partitionsOf(self::UUID_TABLE));
        Assert::assertSame($this->changes('created', $this->daily('2026-01-01', 2)), $this->describe($maintenance->maintain($now)));
    }

    #[Test]
    public function a_busy_table_makes_the_run_give_up_at_its_first_create_and_the_next_run_creates_it(): void
    {
        $maintenance = $this->partitionMaintenance($this->policy([$this->dailyTable()], runwayDays: 1, attempts: 2));
        $now = new DateTimeImmutable('2026-01-10T12:00:00Z');
        $this->lockTable(self::UUID_TABLE);

        try {
            $timeout = $this->thrown(static fn (): PartitionReport => $maintenance->maintain($now));
        } finally {
            $this->unlockTable(self::UUID_TABLE);
        }

        Assert::assertInstanceOf(LockTimeout::class, $timeout);
        Assert::assertSame(DdlStep::Create, $timeout->step);
        Assert::assertSame(self::UUID_TABLE, $timeout->table);
        Assert::assertSame('partition_scratch_p20260110', $timeout->partition);
        Assert::assertSame(2, $timeout->attempts);
        Assert::assertSame([], $this->partitionsOf(self::UUID_TABLE));
        Assert::assertSame($this->changes('created', $this->daily('2026-01-10', 2)), $this->describe($maintenance->maintain($now)));
    }

    #[Test]
    public function a_busy_table_makes_the_run_give_up_before_a_detach_and_leaves_the_partition_attached(): void
    {
        $maintenance = $this->partitionMaintenance($this->policy([$this->dailyTable(retentionDays: 1)], runwayDays: 1, attempts: 2));
        $maintenance->cover($this->range('2026-01-01T00:00:00Z', '2026-01-11T00:00:00Z'));
        $now = new DateTimeImmutable('2026-01-10T12:00:00Z');
        $this->lockTable(self::UUID_TABLE);

        try {
            $timeout = $this->thrown(static fn (): PartitionReport => $maintenance->maintain($now));
        } finally {
            $this->unlockTable(self::UUID_TABLE);
        }

        Assert::assertInstanceOf(LockTimeout::class, $timeout);
        Assert::assertSame(DdlStep::Detach, $timeout->step);
        Assert::assertSame(self::UUID_TABLE, $timeout->table);
        Assert::assertSame('partition_scratch_p20260101', $timeout->partition);
        Assert::assertSame($this->daily('2026-01-01', 11), $this->partitionsOf(self::UUID_TABLE));

        $report = $maintenance->maintain($now);

        Assert::assertSame($this->daily('2026-01-01', 8), $report->partitions(PartitionChangeKind::Dropped));
        Assert::assertSame($this->daily('2026-01-09', 3), $this->partitionsOf(self::UUID_TABLE));
    }

    #[Test]
    public function it_refuses_the_application_connection_before_it_changes_anything(): void
    {
        $maintenance = $this->partitionMaintenance(new PartitionPolicy($this->appConnection(), [$this->dailyTable()], runwayDays: 1));

        $refused = $this->thrown(static fn (): PartitionReport => $maintenance->maintain(new DateTimeImmutable('2026-01-01T00:00:00Z')));

        Assert::assertInstanceOf(OwnerConnectionRequired::class, $refused);
        Assert::assertStringStartsWith('['.OwnerConnectionRequired::CODE.']', $refused->getMessage());
        Assert::assertStringContainsString('['.$this->appConnection().']', $refused->getMessage());
        Assert::assertSame([], $this->partitionsOf(self::UUID_TABLE));
    }

    private function dailyTable(?int $retentionDays = null): PartitionedTable
    {
        return new PartitionedTable(self::UUID_TABLE, PartitionKey::Uuid7, PartitionInterval::Day, $retentionDays);
    }

    /**
     * @param  list<PartitionedTable>  $tables
     */
    private function policy(array $tables, int $runwayDays = PartitionPolicy::DEFAULT_RUNWAY_DAYS, int $attempts = 1): PartitionPolicy
    {
        return new PartitionPolicy($this->ownerConnection(), $tables, $runwayDays, lockTimeoutMs: 100, attempts: $attempts, backoffMs: 10);
    }

    private function range(string $from, string $to): PartitionRange
    {
        return new PartitionRange(new DateTimeImmutable($from), new DateTimeImmutable($to));
    }

    /**
     * The names of the daily partitions of UUID_TABLE from $first for $days days.
     *
     * @return list<string>
     */
    private function daily(string $first, int $days): array
    {
        $names = [];
        $day = new DateTimeImmutable($first.'T00:00:00Z');

        for ($i = 0; $i < $days; $i++) {
            $names[] = self::UUID_TABLE.'_p'.$day->format('Ymd');
            $day = $day->modify('+1 day');
        }

        return $names;
    }

    /**
     * @param  list<string>  $partitions
     * @return list<string>
     */
    private function changes(string $kind, array $partitions): array
    {
        return array_map(static fn (string $partition): string => $kind.' '.$partition, $partitions);
    }

    /**
     * The report's changes as "<kind> <partition>", in order.
     *
     * @return list<string>
     */
    private function describe(PartitionReport $report): array
    {
        return array_map(static fn (PartitionChange $change): string => $change->kind->value.' '.$change->partition, $report->changes);
    }

    /**
     * @param  Closure(): mixed  $callback
     */
    private function thrown(Closure $callback): Throwable
    {
        try {
            $callback();
        } catch (Throwable $thrown) {
            return $thrown;
        }

        Assert::fail('Nothing was thrown.');
    }
}
