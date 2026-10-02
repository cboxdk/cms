<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Boundary;

use Cbox\Cms\Cli\Domain\Dto\CliAnswer;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\CoreServiceProvider;
use Cbox\Cms\Core\Maintenance\Domain\Dto\BootstrapOutcome;
use Cbox\Cms\Core\Process\Boundary\ProcessWorkload;
use Cbox\Cms\Core\Process\Domain\Workload;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use LogicException;

/**
 * What cms:access:bootstrap answers (PRD 5.10, GUARDRAILS 2.1), with exit codes from the error
 * catalog: 0 with the role and the grant; the exit code of maintenance_process_required in a
 * process that serves HTTP, runs queued jobs or has no owner connection, where it never runs; and
 * otherwise the exit code of the first error of the refusal or of the rejected command, such as
 * access_bootstrap_done, access_bootstrap_production or validation_failed.
 */
#[Internal]
final readonly class AccessBootstrapOutput
{
    public function __construct(private RefusalOutput $refusals) {}

    /**
     * A refusal of the process itself, before anything is read: the bootstrap runs only in the
     * maintenance process, a console process with the owner connection.
     */
    public static function refusedProcess(Application $app, Repository $config): ?CatalogError
    {
        $workload = ProcessWorkload::of($app);

        if ($workload === Workload::Console && CoreServiceProvider::ownerConnectionConfigured($config)) {
            return null;
        }

        return new CatalogError(ErrorCode::MaintenanceProcessRequired, null, sprintf(
            'cms:access:bootstrap runs only in the maintenance process, a console process with the owner connection, not %s.',
            $workload === Workload::Console ? 'a console process without the owner connection' : $workload->described(),
        ));
    }

    public function of(BootstrapOutcome $outcome): CliAnswer
    {
        $errors = $outcome->errors();

        if ($errors !== []) {
            return $this->refusals->errors(false, ...$errors);
        }

        if (! $outcome->done() || ! $outcome->granted instanceof WriteResult) {
            throw new LogicException('A bootstrap without errors has committed its grant.');
        }

        $role = $outcome->role?->toString() ?? '';
        $lines = [$outcome->roleCreated instanceof WriteResult
            ? sprintf('Created the bootstrap role %s (%s) with every command and query of the registry and the ceiling sensitive, in changeset %s.', $outcome->handle->value, $role, $outcome->roleCreated->receipt->changesetId?->toString() ?? '')
            : sprintf('The bootstrap role %s (%s) exists already, with every command and query of the registry and the ceiling sensitive.', $outcome->handle->value, $role)];
        $lines[] = sprintf(
            'Granted the bootstrap role to the staff actor %s on the node %s, grant %s, in changeset %s.',
            $outcome->actor?->toString() ?? '',
            $outcome->node?->toString() ?? '',
            $outcome->grant?->toString() ?? '',
            $outcome->granted->receipt->changesetId?->toString() ?? '',
        );

        return new CliAnswer(ExitCode::Ok, $lines);
    }
}
