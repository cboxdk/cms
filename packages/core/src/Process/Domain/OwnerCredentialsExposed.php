<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Process\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use LogicException;

/**
 * A process that serves HTTP or runs queued jobs was started with the owner role's connection in
 * its configuration (PRD 4.2). The core refuses to boot it: any code in a request or a job, an
 * addon's included, could otherwise change the schema and pass the row level security as the
 * owner of the tables, which the app role exists to prevent.
 */
#[Internal]
final class OwnerCredentialsExposed extends LogicException
{
    public const string CODE = 'owner_credentials_exposed';

    public static function in(Workload $workload, string $connection): self
    {
        return new self(sprintf(
            '[%s] The owner connection [%s] is configured in %s. Only the maintenance process, a console process that runs the migrations and the scheduler, may hold the owner role\'s credentials. Remove database.connections.%s from the configuration of the web and queue processes, which must not share a configuration cache with the maintenance process.',
            self::CODE,
            $connection,
            $workload->described(),
            $connection,
        ));
    }
}
