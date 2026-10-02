<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Boundary;

use Cbox\Cms\Cli\Domain\Dto\CliAnswer;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Contracts\Storage\PartitionMissing;
use Cbox\Cms\Core\Maintenance\Domain\Dto\InstalledOperator;
use Cbox\Cms\Core\Maintenance\Domain\InstallRefused;
use Cbox\Cms\Core\Process\Boundary\ProcessWorkload;
use Cbox\Cms\Core\Process\Domain\Workload;
use Illuminate\Contracts\Foundation\Application;

/**
 * What cms:install answers (PRD 3.3, GUARDRAILS 2.1), with exit codes from the error catalog: 0 with
 * the operator's id, created now or found; the exit code of owner_credentials_exposed in a process
 * that serves HTTP or runs queued jobs, where it never runs; of install_owner_connection_required
 * without the owner connection; and of partition_missing when no partition covers the genesis.
 */
#[Internal]
final readonly class InstallOutput
{
    /**
     * A refusal of the process itself, before anything is read: cms:install runs only in a console
     * process, the maintenance process.
     */
    public static function refusedWorkload(Application $app): ?CliAnswer
    {
        $workload = ProcessWorkload::of($app);

        if ($workload === Workload::Console) {
            return null;
        }

        return new CliAnswer(ErrorCode::OwnerCredentialsExposed->entry()->exit, [], [sprintf(
            '[%s] cms:install runs as the owner role and only in the maintenance process, a console process, not %s. Run it from the console of the maintenance process.',
            ErrorCode::OwnerCredentialsExposed->value,
            $workload->described(),
        )]);
    }

    public static function of(InstalledOperator $installed): CliAnswer
    {
        return new CliAnswer(ExitCode::Ok, [$installed->created
            ? sprintf('Created the installation operator %s, an active service actor, in its genesis changeset.', $installed->operator->toString())
            : sprintf('The installation operator is %s. Nothing changed.', $installed->operator->toString())]);
    }

    public static function refused(InstallRefused|PartitionMissing $refusal): CliAnswer
    {
        $code = $refusal instanceof InstallRefused ? ErrorCode::InstallOwnerConnectionRequired : ErrorCode::PartitionMissing;
        $fix = $refusal instanceof PartitionMissing ? ' Run cms:partitions:maintain on the owner connection, then cms:install again.' : '';

        return new CliAnswer($code->entry()->exit, [], [$refusal->getMessage().$fix]);
    }
}
