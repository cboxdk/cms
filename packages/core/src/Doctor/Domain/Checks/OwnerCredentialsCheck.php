<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Checks;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Core\Doctor\Domain\Probes\ProcessProbe;
use Override;

/**
 * Only the process that runs the migrations and partition maintenance holds the owner role's
 * credentials (PRD 4.2, 13). The app role has no DDL so that code in the web and queue processes,
 * an addon or an attacker who runs code there, cannot change the schema or pass the row level
 * security as the tables' owner; an owner connection in their configuration gives that back.
 *
 * It fails when the owner connection is configured in this process and the process is not
 * declared the maintenance process with cms.doctor.maintenance_process, or serves HTTP. It only
 * affects readiness: the kernel does not need the owner connection, and a process without it runs
 * every other check.
 */
#[Internal]
final readonly class OwnerCredentialsCheck implements DoctorCheck
{
    public const string ID = 'postgres.owner_credentials';

    public const string CODE = 'doctor_owner_credentials_exposed';

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

        $servesHttp = $this->process->servesHttp();

        if ($this->maintenanceProcess && ! $servesHttp) {
            return CheckResult::pass($this->id(), false, sprintf(
                'The owner connection %s is configured in this process, which cms.doctor.maintenance_process declares the maintenance process, and it serves no HTTP.',
                $this->ownerConnection,
            ));
        }

        return CheckResult::fail(
            $this->id(),
            false,
            FailureKind::Violation,
            self::CODE,
            'A process that serves HTTP or queued jobs holds the owner role\'s credentials. Any code in it, an addon included, can then change the schema and pass the row level security as the owner of the tables, which the app role exists to prevent.',
            $servesHttp
                ? sprintf('The owner connection %s is configured in a process that serves HTTP.', $this->ownerConnection)
                : sprintf('The owner connection %s is configured in this process, and cms.doctor.maintenance_process does not declare it the maintenance process, so it shares its configuration with the web and queue processes.', $this->ownerConnection),
            sprintf(
                'Give the owner credentials only to the process that runs the migrations, cms:partitions:maintain and its schedule: remove database.connections.%s from the configuration of the web and queue processes, and set cms.doctor.maintenance_process to true in the maintenance process, which serves no HTTP.',
                $this->ownerConnection,
            ),
        );
    }
}
