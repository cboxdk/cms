<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Probes;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Process\Domain\Workload;

/**
 * What this process holds and runs: whether a database connection is configured in it, and
 * whether it serves HTTP, runs queued jobs or runs a console command.
 */
#[Internal]
interface ProcessProbe
{
    /** Whether `database.connections.<name>` is configured in this process. */
    public function connectionConfigured(string $name): bool;

    /** What this process runs, read from the process and not from its configuration. */
    public function workload(): Workload;
}
