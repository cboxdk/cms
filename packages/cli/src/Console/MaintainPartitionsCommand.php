<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Console;

use Cbox\Cms\Cli\Boundary\PartitionRangeOptions;
use Cbox\Cms\Cli\Boundary\PartitionReportOutput;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Partitions\Actions\MaintainPartitions;
use Cbox\Cms\Core\Partitions\Domain\Dto\PartitionRange;
use Cbox\Cms\Core\Partitions\Domain\LockTimeout;
use Cbox\Cms\Core\Partitions\Domain\OwnerConnectionRequired;
use Cbox\Cms\Core\Partitions\Domain\UnmanageableTable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;

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
 * The exit codes come from the error catalog (GUARDRAILS 2.1), through PartitionReportOutput: 0
 * done, 64 invalid options, 75 a lock was busy on every attempt, the maintenance lock or a table's
 * (partition_lock_timeout, try again later), 78 not the owner role's connection
 * (partition_owner_required) or a table it cannot manage (partition_table_unmanageable;
 * configuration: an operator has to fix it, so 78 wins over 75).
 */
#[Internal]
#[Description('Create partitions ahead of the clock and remove partitions past retention, as the owner role')]
#[Signature('cms:partitions:maintain
        {--from= : Only create the partitions that cover this date or ISO 8601 time onwards (needs --to)}
        {--to= : Only create the partitions that cover up to this date or ISO 8601 time (needs --from)}')]
final class MaintainPartitionsCommand extends Command
{
    public function handle(MaintainPartitions $partitions, PartitionReportOutput $output): int
    {
        try {
            $range = PartitionRangeOptions::parse($this->option('from'), $this->option('to'));
            $answer = $output->of($range instanceof PartitionRange ? $partitions->cover($range) : $partitions->maintain());
        } catch (InvalidArgumentException|LockTimeout|OwnerConnectionRequired|UnmanageableTable $refusal) {
            $answer = $output->refused($refusal);
        }

        foreach ($answer->output as $line) {
            $this->output->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        foreach ($answer->errors as $line) {
            $this->output->getErrorStyle()->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        return $answer->exit->value;
    }
}
