<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Postgres;

use Cbox\Cms\Contracts\Attributes\Experimental;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Process\Process;

/**
 * A running child process started by ChildProcesses.
 *
 * The parent synchronises with it through signals: the child calls ProcessContext::signal(),
 * the parent blocks in waitForSignal(). Every wait has a timeout, and every failure of the
 * child fails the test with the child's exit code and output.
 */
#[Experimental]
final class ChildProcess
{
    public const float DEFAULT_TIMEOUT_SECONDS = 10.0;

    private const int POLL_MICROSECONDS = 1_000;

    private string $buffer = '';

    private string $output = '';

    /** @var list<string> */
    private array $signals = [];

    public function __construct(private readonly Process $process) {}

    /**
     * Blocks until the child has signalled $marker. Fails when the child exits first or the
     * timeout passes; on a timeout the child is stopped.
     */
    public function waitForSignal(string $marker, float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): void
    {
        $deadline = hrtime(true) + (int) ($timeoutSeconds * 1e9);

        while (true) {
            $this->collect();

            if (in_array($marker, $this->signals, true)) {
                return;
            }

            if (! $this->process->isRunning()) {
                $this->collect();

                if (in_array($marker, $this->signals, true)) {
                    return;
                }

                throw new AssertionFailedError(sprintf(
                    "The child process exited before it signalled [%s].\n%s",
                    $marker,
                    $this->describe(),
                ));
            }

            if (hrtime(true) > $deadline) {
                $this->stop();

                throw new AssertionFailedError(sprintf(
                    "The child process did not signal [%s] within %.1f s.\n%s",
                    $marker,
                    $timeoutSeconds,
                    $this->describe(),
                ));
            }

            usleep(self::POLL_MICROSECONDS);
        }
    }

    /**
     * Blocks until the child exits and fails unless it exited with code 0.
     */
    public function wait(float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): void
    {
        $deadline = hrtime(true) + (int) ($timeoutSeconds * 1e9);

        while ($this->process->isRunning()) {
            if (hrtime(true) > $deadline) {
                $this->stop();

                throw new AssertionFailedError(sprintf(
                    "The child process did not exit within %.1f s.\n%s",
                    $timeoutSeconds,
                    $this->describe(),
                ));
            }

            $this->collect();
            usleep(self::POLL_MICROSECONDS);
        }

        $this->collect();

        if ($this->process->getExitCode() !== ChildProcessMain::OK) {
            throw new AssertionFailedError("The child process failed.\n".$this->describe());
        }
    }

    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    /**
     * Stops the child. Its backend closes with it, so Postgres releases what it held.
     */
    public function stop(): void
    {
        if ($this->process->isRunning()) {
            $this->process->stop(1);
        }

        $this->collect();
    }

    /**
     * The signals received so far, in order.
     *
     * @return list<string>
     */
    public function signals(): array
    {
        $this->collect();

        return $this->signals;
    }

    /**
     * The child's standard output so far, without the signal lines.
     */
    public function output(): string
    {
        $this->collect();

        return $this->output.$this->buffer;
    }

    public function errorOutput(): string
    {
        return $this->process->getErrorOutput();
    }

    public function exitCode(): ?int
    {
        return $this->process->getExitCode();
    }

    private function collect(): void
    {
        $this->buffer .= $this->process->getIncrementalOutput();

        while (($end = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $end);
            $this->buffer = substr($this->buffer, $end + 1);

            if (str_starts_with($line, ProcessContext::SIGNAL_PREFIX)) {
                $this->signals[] = substr($line, strlen(ProcessContext::SIGNAL_PREFIX));
            } else {
                $this->output .= $line."\n";
            }
        }
    }

    private function describe(): string
    {
        return sprintf(
            "Exit code: %s\nStandard error:\n%s\nStandard output:\n%s",
            $this->process->getExitCode() ?? 'still running',
            rtrim($this->process->getErrorOutput()),
            rtrim($this->output.$this->buffer),
        );
    }
}
