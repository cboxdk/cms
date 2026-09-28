<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Process\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Process\Domain\Workload;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Env;
use Symfony\Component\Console\Input\ArgvInput;

/**
 * Reads what this process runs from the process itself, never from the configuration, which the
 * web, queue and maintenance processes may share through one configuration cache.
 *
 * - HTTP: the application does not run in the console (PHP-FPM, `php artisan serve`), or Octane
 *   started it, which sets `LARAVEL_OCTANE` in the environment of its workers.
 * - Queue: the console command, the first argument as artisan reads it, is one that runs queued
 *   jobs.
 * - Console: any other console command.
 */
#[Internal]
final readonly class ProcessWorkload
{
    /** Set by Octane in the environment of the processes that serve its requests. */
    public const string OCTANE_VARIABLE = 'LARAVEL_OCTANE';

    /** The console commands that run queued jobs, Laravel's and Horizon's. */
    public const array QUEUE_COMMANDS = ['queue:work', 'queue:listen', 'horizon', 'horizon:supervisor', 'horizon:work'];

    public static function of(Application $app): Workload
    {
        if (! $app->runningInConsole() || self::set(Env::get(self::OCTANE_VARIABLE))) {
            return Workload::Http;
        }

        $command = new ArgvInput()->getFirstArgument();

        return in_array($command, self::QUEUE_COMMANDS, true) ? Workload::Queue : Workload::Console;
    }

    private static function set(mixed $value): bool
    {
        return ! in_array($value, [null, false, ''], true);
    }
}
