<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Workbench\Boundary;

use Cbox\Cms\Tooling\Workbench\Domain\WorkbenchServe;
use InvalidArgumentException;

/**
 * The arguments of `composer workbench:serve -- [--port=<n>]`: the host port, WorkbenchServe::DEFAULT_PORT
 * unless one is given.
 */
final readonly class WorkbenchServeOptions
{
    public const string USAGE = 'Usage: php tools/bin/workbench-serve.php [--port=<host port, 1 to 65535>]';

    /**
     * @param  list<string>  $arguments
     */
    public static function parse(array $arguments): WorkbenchServe
    {
        $port = WorkbenchServe::DEFAULT_PORT;

        foreach ($arguments as $argument) {
            if (! str_starts_with($argument, '--port=')) {
                throw new InvalidArgumentException("Unknown argument [{$argument}].");
            }

            $value = substr($argument, strlen('--port='));

            if (preg_match('/^[1-9]\d{0,4}$/', $value) !== 1 || (int) $value > 65535) {
                throw new InvalidArgumentException("The port is a TCP port from 1 to 65535, not [{$value}].");
            }

            $port = (int) $value;
        }

        return WorkbenchServe::on($port);
    }
}
