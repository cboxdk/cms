<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Core\CoreServiceProvider;
use Cbox\Cms\Core\Process\Boundary\ProcessWorkload;
use Cbox\Cms\Core\Process\Domain\Workload;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;

/**
 * The refusal of a process that is not the maintenance process (PRD 4.2, 5.16), for the console
 * commands that run only there: a console process with the owner connection. It is decided before
 * anything is read, with maintenance_process_required, and names the command and what the process
 * is instead.
 */
#[Internal]
final readonly class MaintenanceProcessRefusal
{
    public static function of(Application $app, Repository $config, string $command): ?CatalogError
    {
        $workload = ProcessWorkload::of($app);

        if ($workload === Workload::Console && CoreServiceProvider::ownerConnectionConfigured($config)) {
            return null;
        }

        return new CatalogError(ErrorCode::MaintenanceProcessRequired, null, sprintf(
            '%s runs only in the maintenance process, a console process with the owner connection, not %s.',
            $command,
            $workload === Workload::Console ? 'a console process without the owner connection' : $workload->described(),
        ));
    }
}
