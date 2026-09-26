<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Adapter;

use Cbox\Cms\Tooling\Check\Domain\ProcessOutcome;
use Cbox\Cms\Tooling\Check\Domain\ProcessRunner;
use Closure;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs a command with Symfony Process, without a shell, and keeps standard output and standard
 * error in the order they arrived. A command that does not start, or runs longer than the
 * timeout, or is stopped by a signal, ends without an exit code and with the reason in its output.
 */
final readonly class SymfonyProcessRunner implements ProcessRunner
{
    public function __construct(private float $timeoutSeconds = 3600.0) {}

    public function run(array $command, string $directory, array $environment = [], ?Closure $echo = null): ProcessOutcome
    {
        $process = new Process($command, $directory, $environment, null, $this->timeoutSeconds);
        $output = '';
        $started = hrtime(true);
        $collect = static function (string $type, string $buffer) use (&$output, $echo): void {
            $output .= $buffer;

            if ($echo instanceof Closure) {
                $echo($buffer);
            }
        };

        try {
            $exitCode = $process->run($collect);
        } catch (ProcessTimedOutException) {
            return new ProcessOutcome(null, $output.sprintf("\nThe command timed out after %.0f s.\n", $this->timeoutSeconds), $this->since($started), true);
        } catch (ProcessSignaledException $exception) {
            return new ProcessOutcome(null, $output.sprintf("\nThe command was stopped by signal %d.\n", $exception->getSignal()), $this->since($started));
        } catch (ProcessStartFailedException $exception) {
            return new ProcessOutcome(null, $exception->getMessage()."\n", $this->since($started));
        }

        return new ProcessOutcome($exitCode, $output, $this->since($started));
    }

    private function since(int|float $started): float
    {
        return (hrtime(true) - $started) / 1e9;
    }
}
