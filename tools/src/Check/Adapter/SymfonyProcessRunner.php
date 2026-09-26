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
 *
 * A command in a process group of its own starts through tools/bin/process-group.php, which makes
 * it the leader of a new group. When the command ends, also by a fatal error, a signal or the
 * timeout, the runner sends SIGTERM to what is left of the group, and SIGKILL to what is still
 * there after TERM_GRACE_SECONDS, so no process it started outlives the step and holds its output
 * open. A SIGINT, SIGTERM or SIGHUP to the runner while the command runs is passed on to the
 * group before the runner ends by it, because the group is no longer the terminal's.
 */
final readonly class SymfonyProcessRunner implements ProcessRunner
{
    public const float TERM_GRACE_SECONDS = 5.0;

    public const float KILL_GRACE_SECONDS = 2.0;

    private const string PROCESS_GROUP = __DIR__.'/../../../bin/process-group.php';

    private const array FORWARDED_SIGNALS = [SIGINT, SIGTERM, SIGHUP];

    public function __construct(private float $timeoutSeconds = 3600.0) {}

    public function run(array $command, string $directory, array $environment = [], ?Closure $echo = null, bool $ownProcessGroup = false): ProcessOutcome
    {
        $process = new Process(
            $ownProcessGroup ? [PHP_BINARY, self::PROCESS_GROUP, ...$command] : $command,
            $directory,
            $environment,
            null,
            $this->timeoutSeconds,
        );
        $output = '';
        $started = hrtime(true);
        $collect = static function (string $type, string $buffer) use (&$output, $echo): void {
            $output .= $buffer;

            if ($echo instanceof Closure) {
                $echo($buffer);
            }
        };

        // The handlers are in place before the command starts, so no signal slips past them.
        $group = null;
        $restoreSignals = $ownProcessGroup ? $this->forwardSignals($group) : null;
        $exitCode = null;
        $timedOut = false;

        try {
            $process->start($collect);
            $group = $ownProcessGroup ? $process->getPid() : null;
            $exitCode = $process->wait();
        } catch (ProcessStartFailedException $exception) {
            $output = $exception->getMessage()."\n";
        } catch (ProcessTimedOutException) {
            $timedOut = true;
            $output .= sprintf("\nThe command timed out after %.0f s.\n", $this->timeoutSeconds);
        } catch (ProcessSignaledException $exception) {
            $output .= sprintf("\nThe command was stopped by signal %d.\n", $exception->getSignal());
        } finally {
            if ($group !== null) {
                $output .= $this->stopGroup($group);
            }

            if ($restoreSignals instanceof Closure) {
                $restoreSignals();
            }
        }

        return new ProcessOutcome($exitCode, $output, $this->since($started), $timedOut);
    }

    /**
     * Sends SIGTERM to the processes left in the group, then SIGKILL to those still there after
     * the grace time, and says so in the output. A zombie whose parent died is gone as soon as
     * PID 1 reaps it; the runner stops waiting for the group after the second grace time.
     */
    private function stopGroup(int $group): string
    {
        if (! $this->groupExists($group)) {
            return '';
        }

        posix_kill(-$group, SIGTERM);

        if ($this->waitForGroup($group, self::TERM_GRACE_SECONDS)) {
            return "\nThe command left processes in its process group; they were stopped with SIGTERM.\n";
        }

        posix_kill(-$group, SIGKILL);
        $this->waitForGroup($group, self::KILL_GRACE_SECONDS);

        return sprintf("\nThe command left processes in its process group that ignored SIGTERM for %.0f s; they were killed with SIGKILL.\n", self::TERM_GRACE_SECONDS);
    }

    /**
     * Whether the group is empty within the given time.
     */
    private function waitForGroup(int $group, float $seconds): bool
    {
        $deadline = hrtime(true) + (int) ($seconds * 1e9);

        while ($this->groupExists($group)) {
            if (hrtime(true) >= $deadline) {
                return false;
            }

            usleep(20_000);
        }

        return true;
    }

    private function groupExists(int $group): bool
    {
        return posix_kill(-$group, 0);
    }

    /**
     * Passes SIGINT, SIGTERM and SIGHUP to the group while the command runs, then ends the runner
     * by the same signal. A signal the runner ignores stays ignored, and one that arrives before
     * the command has a group only ends the runner. Returns what puts the handlers back.
     *
     * @return Closure(): void
     */
    private function forwardSignals(?int &$group): Closure
    {
        $async = pcntl_async_signals(true);
        $forwarded = array_values(array_filter(
            self::FORWARDED_SIGNALS,
            static fn (int $signal): bool => pcntl_signal_get_handler($signal) === SIG_DFL,
        ));

        $restore = static function () use ($forwarded, $async): void {
            foreach ($forwarded as $signal) {
                pcntl_signal($signal, SIG_DFL);
            }

            pcntl_async_signals($async);
        };

        foreach ($forwarded as $signal) {
            pcntl_signal($signal, function (int $received) use (&$group, $restore): void {
                if ($group !== null) {
                    posix_kill(-$group, $received);

                    if (! $this->waitForGroup($group, self::TERM_GRACE_SECONDS)) {
                        posix_kill(-$group, SIGKILL);
                    }
                }

                $restore();
                posix_kill(posix_getpid(), $received);
            });
        }

        return $restore;
    }

    private function since(int|float $started): float
    {
        return (hrtime(true) - $started) / 1e9;
    }
}
