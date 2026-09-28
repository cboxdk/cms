<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Checks;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Core\Doctor\Domain\Probes\ProcessProbe;
use Cbox\Cms\Core\Process\Domain\Workload;
use Override;

/**
 * Only the process that runs the migrations and partition maintenance holds the owner role's
 * credentials (PRD 4.2, 13). The app role has no DDL so that code in the web and queue processes,
 * an addon or an attacker who runs code there, cannot change the schema or pass the row level
 * security as the tables' owner; an owner connection in their configuration gives that back.
 *
 * It fails when the owner connection is configured in this process and the process is not a
 * console process that its own environment declares the maintenance process with
 * CBOX_CMS_MAINTENANCE_PROCESS=true. The declaration is read from the process's environment, not
 * from the configuration, which the web, queue and maintenance processes may share through one
 * configuration cache. A process that serves HTTP or runs queued jobs with the owner connection
 * does not boot at all (CoreServiceProvider); this check covers the console processes, such as a
 * cms:doctor run in the web or queue container. It only affects readiness: the kernel does not
 * need the owner connection, and a process without it runs every other check.
 */
#[Internal]
final readonly class OwnerCredentialsCheck implements DoctorCheck
{
    public const string ID = 'postgres.owner_credentials';

    public const string CODE = 'doctor_owner_credentials_exposed';

    /** The environment variable, set to true, that declares a process the maintenance process. */
    public const string MAINTENANCE_VARIABLE = 'CBOX_CMS_MAINTENANCE_PROCESS';

    public function __construct(
        private ProcessProbe $process,
        private string $ownerConnection,
        private bool $maintenanceProcess,
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
        return [];
    }

    #[Override]
    public function run(): CheckResult
    {
        if (! $this->process->connectionConfigured($this->ownerConnection)) {
            return CheckResult::pass($this->id(), false, sprintf(
                'The owner connection %s is not configured in this process, so code in it cannot log in as the owner role.',
                $this->ownerConnection,
            ));
        }

        $workload = $this->process->workload();

        if ($this->maintenanceProcess && $workload->mayHoldOwnerCredentials()) {
            return CheckResult::pass($this->id(), false, sprintf(
                'The owner connection %s is configured in this process, which %s=true in its environment declares the maintenance process, and it serves no HTTP and runs no queued jobs.',
                $this->ownerConnection,
                self::MAINTENANCE_VARIABLE,
            ));
        }

        return CheckResult::fail(
            $this->id(),
            false,
            FailureKind::Violation,
            self::CODE,
            'A process that serves HTTP or queued jobs holds the owner role\'s credentials. Any code in it, an addon included, can then change the schema and pass the row level security as the owner of the tables, which the app role exists to prevent.',
            $workload === Workload::Console
                ? sprintf('The owner connection %s is configured in this process, and %s in its environment does not declare it the maintenance process, so it may be a web or queue process that shares the maintenance process\'s configuration.', $this->ownerConnection, self::MAINTENANCE_VARIABLE)
                : sprintf('The owner connection %s is configured in %s.', $this->ownerConnection, $workload->described()),
            sprintf(
                'Give the owner credentials only to the process that runs the migrations, cms:partitions:maintain and its schedule: remove database.connections.%s from the configuration of the web and queue processes, which must not share a configuration cache with the maintenance process, and set %s=true in the environment of the maintenance process alone, not in .env or the configuration.',
                $this->ownerConnection,
                self::MAINTENANCE_VARIABLE,
            ),
        );
    }
}
