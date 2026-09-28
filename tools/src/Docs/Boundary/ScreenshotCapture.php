<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Boundary;

use Cbox\Cms\Tooling\Docs\Domain\Screenshot;
use Cbox\Cms\Tooling\Docs\Domain\TerminalSvg;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs the command of a screenshot from a root directory and draws its output as a terminal
 * (TerminalSvg). The command runs with colour forced on (`FORCE_COLOR=1`, `NO_COLOR` removed) and
 * the terminal's width in `COLUMNS`, with no time limit, and its standard output and error in the
 * order it wrote them. An exit code other than the one the shot expects throws, so a picture of a
 * broken environment is never drawn.
 */
final readonly class ScreenshotCapture
{
    public static function capture(string $root, Screenshot $shot): string
    {
        $process = new Process($shot->command, $root, [
            'FORCE_COLOR' => '1',
            'NO_COLOR' => false,
            'COLUMNS' => (string) $shot->columns,
            'TERM' => 'xterm-256color',
        ], null, null);

        $output = '';
        $process->run(static function (string $type, string $chunk) use (&$output): void {
            $output .= $chunk;
        });

        $exitCode = $process->getExitCode();

        if ($exitCode !== $shot->exitCode) {
            throw new RuntimeException(sprintf(
                "%s exited with %s, and the screenshot %s expects %d. Its output:\n%s",
                $shot->commandLine(),
                $exitCode === null ? 'no exit code' : (string) $exitCode,
                $shot->key,
                $shot->exitCode,
                $output,
            ));
        }

        return TerminalSvg::render($shot->promptLine(), $output, $shot->columns);
    }
}
