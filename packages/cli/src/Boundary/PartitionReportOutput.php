<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Boundary;

use Cbox\Cms\Cli\Domain\Dto\CliAnswer;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Core\Partitions\Domain\Dto\FailedTable;
use Cbox\Cms\Core\Partitions\Domain\Dto\GaveUpStep;
use Cbox\Cms\Core\Partitions\Domain\Dto\PartitionChange;
use Cbox\Cms\Core\Partitions\Domain\Dto\PartitionReport;
use Cbox\Cms\Core\Partitions\Domain\Dto\SequenceRunway;
use Cbox\Cms\Core\Partitions\Domain\Dto\TableRunway;
use Cbox\Cms\Core\Partitions\Domain\LockTimeout;
use Cbox\Cms\Core\Partitions\Domain\OwnerConnectionRequired;
use Cbox\Cms\Core\Partitions\Domain\UnmanageableTable;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * The answer of cms:partitions:maintain (GUARDRAILS 2.1: exit codes from the error catalog), and
 * what it logs.
 *
 * A run's report prints each change, each root it analyzed and each table's runway on standard
 * output, then each step that gave up on a lock and each table that failed on standard error. It
 * exits 0, or with the catalog's exit code of partition_table_unmanageable (78) when a table
 * failed, which an operator has to fix and so wins, else of partition_lock_timeout (75) when a
 * step gave up.
 *
 * A run that did not start, or stopped as a whole, prints the reason on standard error and exits
 * with the catalog's code for it: 64 (usage) for invalid --from and --to or a range the action
 * will not cover, and the exit code of partition_lock_timeout, partition_owner_required or
 * partition_table_unmanageable.
 */
#[Internal]
final readonly class PartitionReportOutput
{
    public function __construct(private LoggerInterface $log) {}

    public function of(PartitionReport $report): CliAnswer
    {
        $output = [
            ...array_map(static fn (PartitionChange $change): string => sprintf('%s %s.%s', $change->kind->value, $change->table, $change->partition), $report->changes),
            ...array_map(static fn (string $root): string => sprintf('analyzed %s', $root), $report->analyzed),
            ...array_map(static fn (TableRunway $runway): string => sprintf('runway %s until %s', $runway->table, self::runway($runway)), $report->runways),
            sprintf('Partitions maintained as role %s: %d changes.', $report->role, count($report->changes)),
        ];

        $this->log->info('Partition maintenance ran.', [
            'role' => $report->role,
            'changes' => array_map(static fn (PartitionChange $change): string => $change->kind->value.' '.$change->partition, $report->changes),
            'runways' => array_map(static fn (TableRunway $runway): string => $runway->table.' '.self::runway($runway), $report->runways),
            'analyzed' => $report->analyzed,
        ]);

        $errors = [
            ...array_map($this->gaveUp(...), $report->gaveUp),
            ...array_map($this->failed(...), $report->failed),
        ];

        $exit = match (true) {
            $report->failed !== [] => ErrorCode::PartitionTableUnmanageable->entry()->exit,
            $report->gaveUp !== [] => ErrorCode::PartitionLockTimeout->entry()->exit,
            default => ExitCode::Ok,
        };

        return new CliAnswer($exit, $output, $errors);
    }

    /**
     * The answer to a run that did not start or stopped as a whole: invalid options or a range the
     * action refuses, the maintenance lock busy on every attempt, not the owner connection, or a
     * table it cannot manage.
     */
    public function refused(InvalidArgumentException|LockTimeout|OwnerConnectionRequired|UnmanageableTable $refusal): CliAnswer
    {
        return match (true) {
            $refusal instanceof LockTimeout => new CliAnswer(ErrorCode::PartitionLockTimeout->entry()->exit, [], [$this->gaveUp(GaveUpStep::of($refusal))]),
            $refusal instanceof OwnerConnectionRequired => new CliAnswer(ErrorCode::PartitionOwnerRequired->entry()->exit, [], [$refusal->getMessage()]),
            $refusal instanceof UnmanageableTable => new CliAnswer(ErrorCode::PartitionTableUnmanageable->entry()->exit, [], [$refusal->getMessage()]),
            default => new CliAnswer(ExitCode::Usage, [], [$refusal->getMessage()]),
        };
    }

    private function failed(FailedTable $table): string
    {
        $this->log->error('Partition maintenance could not manage a table.', [
            'code' => UnmanageableTable::CODE,
            'table' => $table->table,
            'partition' => $table->partition,
            'cause' => $table->cause,
        ]);

        return $table->message;
    }

    private function gaveUp(GaveUpStep $step): string
    {
        $this->log->warning('Partition maintenance gave up on a lock.', [
            'code' => LockTimeout::CODE,
            'step' => $step->step->value,
            'table' => $step->table,
            'partition' => $step->partition,
            'attempts' => $step->attempts,
            'cause' => $step->cause,
        ]);

        return $step->message;
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
