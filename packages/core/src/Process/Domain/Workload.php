<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Process\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * What a process of the application runs: HTTP requests, queued jobs, or console commands such as
 * the migrations and the scheduler. Only a console process may hold the owner role's credentials
 * (PRD 4.2): the code of every request and job would otherwise run next to them.
 */
#[Internal]
enum Workload
{
    /** HTTP requests: PHP-FPM, `php artisan serve`, or an Octane worker on Swoole, RoadRunner or FrankenPHP. */
    case Http;

    /** Queued jobs: `queue:work`, `queue:listen` or a Horizon supervisor or worker. */
    case Queue;

    /** Any other console command, such as `migrate`, `schedule:work` or `cms:doctor`. */
    case Console;

    /** Whether the owner role's credentials may be configured in a process of this workload. */
    public function mayHoldOwnerCredentials(): bool
    {
        return $this === self::Console;
    }

    /** The process, for a message: "a process that serves HTTP". */
    public function described(): string
    {
        return match ($this) {
            self::Http => 'a process that serves HTTP',
            self::Queue => 'a process that runs queued jobs',
            self::Console => 'a console process',
        };
    }
}
