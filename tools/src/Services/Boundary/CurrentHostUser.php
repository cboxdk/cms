<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Services\Boundary;

use Cbox\Cms\Tooling\Services\Domain\HostUser;
use Symfony\Component\Process\Process;
use UnexpectedValueException;

/**
 * The user and group this process runs as, from `id -u` and `id -g`, as `composer services:up`
 * exported them before it moved into tools/bin/services.php.
 */
final readonly class CurrentHostUser
{
    public static function read(): HostUser
    {
        return new HostUser(self::id('-u'), self::id('-g'));
    }

    private static function id(string $option): int
    {
        $process = new Process(['id', $option], null, null, null, 10);
        $process->run();
        $output = trim($process->getOutput());

        if (! $process->isSuccessful() || preg_match('/^\d+$/', $output) !== 1) {
            throw new UnexpectedValueException("`id {$option}` did not print a number: ".trim($process->getErrorOutput().' '.$output));
        }

        return (int) $output;
    }
}
