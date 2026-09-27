<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Checks;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Core\Doctor\Domain\Dto\PartitionCoverage;
use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Doctor\Domain\Probes\PartitionRunwayProbe;
use DateTimeImmutable;
use Override;

/**
 * Every managed table has partitions at least runway days ahead of the Clock, without a gap
 * (PRD 4, 4.2).
 *
 * The tables have no DEFAULT partition, so a write past the last partition fails with
 * PartitionMissing. The scheduler extends the runway every hour, so a short runway means the
 * scheduler has stopped. The check does not block: the kernel must start for the scheduler to run.
 */
#[Internal]
final readonly class PartitionRunwayCheck implements DoctorCheck
{
    public const string ID = 'partitions.runway';

    public const string CODE_SHORT = 'doctor_partition_runway_short';

    public const string CODE_UNMANAGEABLE = 'doctor_partition_table_unmanageable';

    private const string TIME = 'Y-m-d\TH:i:s\Z';

    /**
     * @param  int  $runwayDays  how many days ahead of now every table must have partitions
     */
    public function __construct(
        private PartitionRunwayProbe $partitions,
        private Clock $clock,
        private int $runwayDays,
    ) {}

    #[Override]
    public function id(): CheckId
    {
        return new CheckId(self::ID);
    }

    #[Override]
    public function blocking(): bool
    {
        return false;
    }

    #[Override]
    public function requires(): array
    {
        return [new CheckId(PostgresReachableCheck::ID)];
    }

    #[Override]
    public function run(): CheckResult
    {
        $now = $this->clock->now();

        try {
            $runways = $this->partitions->coverage($now);
        } catch (ProbeFailed $failed) {
            return CheckResult::fail(
                $this->id(),
                false,
                $failed->kind,
                self::CODE_UNMANAGEABLE,
                'The doctor could not read the partitions of the tables in cbox-cms.database.partitions.tables.',
                $failed->cause,
                'Run the migrations as the owner role, and check the tables listed in cbox-cms.database.partitions.tables.',
            );
        }

        if ($runways === []) {
            return CheckResult::pass($this->id(), false, 'No tables are listed in cbox-cms.database.partitions.tables, so there is no runway to check.');
        }

        $unmanageable = array_values(array_filter($runways, static fn (PartitionCoverage $runway): bool => ! $runway->isManageable()));

        if ($unmanageable !== []) {
            return $this->unmanageable($unmanageable, array_values(array_filter($runways, static fn (PartitionCoverage $runway): bool => $runway->isManageable())), $now);
        }

        $needed = $now->modify(sprintf('+%d days', $this->runwayDays));
        $short = array_values(array_filter(
            $runways,
            static fn (PartitionCoverage $runway): bool => ! $runway->coveredUntil instanceof DateTimeImmutable || $runway->coveredUntil < $needed,
        ));

        if ($short === []) {
            return CheckResult::pass($this->id(), false, sprintf(
                'Every managed table has partitions at least %d days ahead: %s.',
                $this->runwayDays,
                $this->describe($runways, $now),
            ));
        }

        return CheckResult::fail(
            $this->id(),
            false,
            FailureKind::Violation,
            self::CODE_SHORT,
            sprintf(
                'Some partitioned tables have partitions for less than %d days ahead. A write past the last partition fails with partition_missing, because the tables have no DEFAULT partition.',
                $this->runwayDays,
            ),
            sprintf('At %s: %s.', $now->format(self::TIME), $this->describe($short, $now)),
            'Run php artisan cms:partitions:maintain, which runs as the owner role, and check that the scheduler runs it every hour (php artisan schedule:list).',
        );
    }

    /**
     * Fails for the tables the partition manager cannot manage, and names the coverage of the
     * other tables, which the manager still maintains.
     *
     * @param  non-empty-list<PartitionCoverage>  $unmanageable
     * @param  list<PartitionCoverage>  $others
     */
    private function unmanageable(array $unmanageable, array $others, DateTimeImmutable $now): CheckResult
    {
        $cause = implode(' ', array_map(
            static fn (PartitionCoverage $table): string => sprintf('%s: %s', $table->table, $table->unmanageable),
            $unmanageable,
        ));

        if ($others !== []) {
            $cause .= sprintf(' The other tables at %s: %s.', $now->format(self::TIME), $this->describe($others, $now));
        }

        return CheckResult::fail(
            $this->id(),
            false,
            FailureKind::Violation,
            self::CODE_UNMANAGEABLE,
            sprintf(
                'The partition manager cannot manage %s %s listed in cbox-cms.database.partitions.tables, so %s no new partitions; it still maintains the other tables.',
                count($unmanageable) === 1 ? 'the table' : 'the tables',
                implode(', ', array_map(static fn (PartitionCoverage $table): string => '"'.$table->table.'"', $unmanageable)),
                count($unmanageable) === 1 ? 'it gets' : 'they get',
            ),
            $cause,
            'Run the migrations as the owner role, and fix or remove each table named in the cause; then run php artisan cms:partitions:maintain.',
        );
    }

    /**
     * @param  list<PartitionCoverage>  $runways
     */
    private function describe(array $runways, DateTimeImmutable $now): string
    {
        return implode(', ', array_map(
            static fn (PartitionCoverage $runway): string => $runway->coveredUntil instanceof DateTimeImmutable
                ? sprintf(
                    '%s until %s (%.1f days)',
                    $runway->table,
                    $runway->coveredUntil->format(self::TIME),
                    ($runway->coveredUntil->getTimestamp() - $now->getTimestamp()) / 86_400,
                )
                : sprintf('%s has no partition for now', $runway->table),
            $runways,
        ));
    }
}
