<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Console;

use Cbox\Cms\Cli\Boundary\PartitionRangeOptions;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Partitions\Actions\MaintainPartitions;
use Cbox\Cms\Core\Partitions\Domain\Dto\FailedTable;
use Cbox\Cms\Core\Partitions\Domain\Dto\GaveUpStep;
use Cbox\Cms\Core\Partitions\Domain\Dto\PartitionChange;
use Cbox\Cms\Core\Partitions\Domain\Dto\PartitionRange;
use Cbox\Cms\Core\Partitions\Domain\Dto\PartitionReport;
use Cbox\Cms\Core\Partitions\Domain\Dto\SequenceRunway;
use Cbox\Cms\Core\Partitions\Domain\Dto\TableRunway;
use Cbox\Cms\Core\Partitions\Domain\LockTimeout;
use Cbox\Cms\Core\Partitions\Domain\OwnerConnectionRequired;
use Cbox\Cms\Core\Partitions\Domain\UnmanageableTable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * `cms:partitions:maintain`: keeps the range partitions of the tables in
 * `cbox-cms.database.partitions.tables` (PRD 4, 4.2). The core schedules it every hour.
 *
 * Without options it creates partitions from now to the runway's end, removes those past
 * retention and runs ANALYZE on the partitioned parents whose partitions it changed, because
 * autovacuum never analyzes a partitioned table. With --from and --to it only creates the
 * partitions that cover that range, for rows that arrive with past or future keys, and the runway
 * ahead of the sequence of each table partitioned on a sequence.
 *
 * A table whose lock stays busy does not stop the others, and nor does a table it cannot manage
 * (missing, not partitioned by range, with a DEFAULT partition, or a detached table in its runway
 * that cannot be attached again): the command prints what the run did, then each step that gave
 * up and each table that failed, and exits after every other table has been maintained.
 *
 * Exit codes: 0 done, 2 invalid options, 75 a lock was busy on every attempt, the maintenance
 * lock or a table's (try again later), 78 not the owner role's connection, or a table it cannot
 * manage (configuration: an operator has to fix it, so 78 wins over 75).
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

    /** EX_CONFIG from sysexits.h: a table in the policy cannot be managed as it is. */
    public const int EXIT_UNMANAGEABLE = 78;

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
            $this->gaveUp(GaveUpStep::of($timeout), $log);

            return self::EXIT_LOCK_TIMEOUT;
        } catch (OwnerConnectionRequired $notOwner) {
            $this->error($notOwner->getMessage());

            return self::EXIT_NOT_OWNER;
        } catch (UnmanageableTable $unmanageable) {
            $this->error($unmanageable->getMessage());

            return self::EXIT_UNMANAGEABLE;
        }

        $this->report($report, $log);

        foreach ($report->gaveUp as $step) {
            $this->gaveUp($step, $log);
        }

        foreach ($report->failed as $table) {
            $this->failed($table, $log);
        }

        return match (true) {
            $report->failed !== [] => self::EXIT_UNMANAGEABLE,
            $report->gaveUp !== [] => self::EXIT_LOCK_TIMEOUT,
            default => self::SUCCESS,
        };
    }

    private function failed(FailedTable $table, LoggerInterface $log): void
    {
        $log->error('Partition maintenance could not manage a table.', [
            'code' => UnmanageableTable::CODE,
            'table' => $table->table,
            'partition' => $table->partition,
            'cause' => $table->cause,
        ]);
        $this->error($table->message);
    }

    private function gaveUp(GaveUpStep $step, LoggerInterface $log): void
    {
        $log->warning('Partition maintenance gave up on a lock.', [
            'code' => LockTimeout::CODE,
            'step' => $step->step->value,
            'table' => $step->table,
            'partition' => $step->partition,
            'attempts' => $step->attempts,
            'cause' => $step->cause,
        ]);
        $this->error($step->message);
    }

    private function report(PartitionReport $report, LoggerInterface $log): void
    {
        foreach ($report->changes as $change) {
            $this->line(sprintf('%s %s.%s', $change->kind->value, $change->table, $change->partition));
        }

        foreach ($report->analyzed as $root) {
            $this->line(sprintf('analyzed %s', $root));
        }

        foreach ($report->runways as $runway) {
            $this->line(sprintf('runway %s until %s', $runway->table, self::runway($runway)));
        }

        $this->info(sprintf('Partitions maintained as role %s: %d changes.', $report->role, count($report->changes)));

        $log->info('Partition maintenance ran.', [
            'role' => $report->role,
            'changes' => array_map(static fn (PartitionChange $change): string => $change->kind->value.' '.$change->partition, $report->changes),
            'runways' => array_map(static fn (TableRunway $runway): string => $runway->table.' '.self::runway($runway), $report->runways),
            'analyzed' => $report->analyzed,
        ]);
    }

    /**
     * Where a table's runway ends: a time for a table partitioned on time, and for one partitioned
     * on a sequence the id with the empty partitions ahead of the sequence's current value; none
     * when no partition holds now or the current value.
     */
    private static function runway(TableRunway $runway): string
    {
        $sequence = $runway->sequence;

        if (! $sequence instanceof SequenceRunway) {
            return $runway->coveredUntil?->format('Y-m-d\TH:i:s\Z') ?? 'none';
        }

        return $sequence->coveredUntil === null
            ? sprintf('none (current id %d)', $sequence->current)
            : sprintf('id %d (%d %s ahead of id %d)', $sequence->coveredUntil, $sequence->partitionsAhead, $sequence->partitionsAhead === 1 ? 'partition' : 'partitions', $sequence->current);
    }
}
