<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Console;

use Cbox\Cms\Cli\Boundary\PartitionRangeOptions;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Partitions\Actions\MaintainPartitions;
use Cbox\Cms\Core\Partitions\Domain\Dto\PartitionChange;
use Cbox\Cms\Core\Partitions\Domain\Dto\PartitionRange;
use Cbox\Cms\Core\Partitions\Domain\Dto\PartitionReport;
use Cbox\Cms\Core\Partitions\Domain\Dto\TableRunway;
use Cbox\Cms\Core\Partitions\Domain\LockTimeout;
use Cbox\Cms\Core\Partitions\Domain\OwnerConnectionRequired;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * `cms:partitions:maintain`: keeps the range partitions of the tables in
 * `cms.database.partitions.tables` (PRD 4, 4.2). The core schedules it every hour.
 *
 * Without options it creates partitions from now to the runway's end and removes those past
 * retention. With --from and --to it only creates the partitions that cover that range, for rows
 * that arrive with past or future keys.
 *
 * A table whose lock stays busy does not stop the others: the command prints what the run did,
 * then each step that gave up, and exits 75 after every table has been tried.
 *
 * Exit codes: 0 done, 2 invalid options, 75 a lock was busy on every attempt, the maintenance
 * lock or a table's (try again later), 78 not the owner role's connection (configuration).
 */
#[Internal]
#[Description('Create partitions ahead of the clock and remove partitions past retention, as the owner role')]
#[Signature('cms:partitions:maintain
        {--from= : Only create the partitions that cover this date or ISO 8601 time onwards (needs --to)}
        {--to= : Only create the partitions that cover up to this date or ISO 8601 time (needs --from)}')]
final class MaintainPartitionsCommand extends Command
{
    public const int EXIT_INVALID = 2;

    /** EX_TEMPFAIL from sysexits.h. */
    public const int EXIT_LOCK_TIMEOUT = 75;

    /** EX_CONFIG from sysexits.h. */
    public const int EXIT_NOT_OWNER = 78;

    public function handle(MaintainPartitions $partitions, LoggerInterface $log): int
    {
        try {
            $range = PartitionRangeOptions::parse($this->option('from'), $this->option('to'));
        } catch (InvalidArgumentException $invalid) {
            $this->error($invalid->getMessage());

            return self::EXIT_INVALID;
        }

        try {
            $report = $range instanceof PartitionRange ? $partitions->cover($range) : $partitions->maintain();
        } catch (InvalidArgumentException $invalid) {
            $this->error($invalid->getMessage());

            return self::EXIT_INVALID;
        } catch (LockTimeout $timeout) {
            $this->gaveUp($timeout, $log);

            return self::EXIT_LOCK_TIMEOUT;
        } catch (OwnerConnectionRequired $notOwner) {
            $this->error($notOwner->getMessage());

            return self::EXIT_NOT_OWNER;
        }

        $this->report($report, $log);

        foreach ($report->gaveUp as $timeout) {
            $this->gaveUp($timeout, $log);
        }

        return $report->isComplete() ? self::SUCCESS : self::EXIT_LOCK_TIMEOUT;
    }

    private function gaveUp(LockTimeout $timeout, LoggerInterface $log): void
    {
        $log->warning('Partition maintenance gave up on a lock.', ['code' => LockTimeout::CODE, 'step' => $timeout->step->value, 'table' => $timeout->table, 'partition' => $timeout->partition, 'attempts' => $timeout->attempts]);
        $this->error($timeout->getMessage());
    }

    private function report(PartitionReport $report, LoggerInterface $log): void
    {
        foreach ($report->changes as $change) {
            $this->line(sprintf('%s %s.%s', $change->kind->value, $change->table, $change->partition));
        }

        foreach ($report->runways as $runway) {
            $this->line(sprintf(
                'runway %s until %s',
                $runway->table,
                $runway->coveredUntil?->format('Y-m-d\TH:i:s\Z') ?? 'none',
            ));
        }

        $this->info(sprintf('Partitions maintained as role %s: %d changes.', $report->role, count($report->changes)));

        $log->info('Partition maintenance ran.', [
            'role' => $report->role,
            'changes' => array_map(static fn (PartitionChange $change): string => $change->kind->value.' '.$change->partition, $report->changes),
            'runways' => array_map(static fn (TableRunway $runway): string => $runway->table.' '.($runway->coveredUntil?->format('Y-m-d\TH:i:s\Z') ?? 'none'), $report->runways),
        ]);
    }
}
