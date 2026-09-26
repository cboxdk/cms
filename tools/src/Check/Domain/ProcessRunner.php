<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Domain;

use Closure;

/**
 * Runs a command and collects its standard output and standard error, interleaved.
 */
interface ProcessRunner
{
    /**
     * @param  list<string>  $command  the program and its arguments, never a shell string
     * @param  array<string, string>  $environment  variables set on top of the inherited environment
     * @param  (Closure(string): void)|null  $echo  also receives the output as it arrives
     */
    public function run(array $command, string $directory, array $environment = [], ?Closure $echo = null): ProcessOutcome;
}
