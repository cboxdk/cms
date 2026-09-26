<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Domain;

use Closure;

/**
 * Runs a command and collects its standard output and standard error, interleaved. A command can
 * run as the leader of a process group of its own: when it ends, however it ends, the runner
 * kills whatever is left in the group, so a process it started cannot outlive it and hold its
 * output open.
 */
interface ProcessRunner
{
    /**
     * @param  list<string>  $command  the program and its arguments, never a shell string
     * @param  array<string, string>  $environment  variables set on top of the inherited environment
     * @param  (Closure(string): void)|null  $echo  also receives the output as it arrives
     * @param  bool  $ownProcessGroup  run the command in a new process group and kill the group when it ends
     */
    public function run(array $command, string $directory, array $environment = [], ?Closure $echo = null, bool $ownProcessGroup = false): ProcessOutcome;
}
