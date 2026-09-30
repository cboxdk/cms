<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Scale\Boundary;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * The machine a scale check ran on, for the record in PROGRESS.md (PRD 23: the scale profile names
 * the machine's resources): the CPU, its cores and memory, the load when the check started, and
 * the CPUs and memory Docker gives its containers.
 */
final readonly class MachineDescription
{
    /**
     * @return list<string>
     */
    public static function lines(): array
    {
        $cpu = self::first(['sysctl', '-n', 'machdep.cpu.brand_string'])
            ?? self::first(['sh', '-c', "grep -m1 'model name' /proc/cpuinfo | cut -d: -f2"])
            ?? 'unknown CPU';
        $cores = self::first(['sysctl', '-n', 'hw.ncpu']) ?? self::first(['nproc']) ?? '?';
        $bytes = self::first(['sysctl', '-n', 'hw.memsize']) ?? self::first(['sh', '-c', "awk '/MemTotal/ {print $2 * 1024}' /proc/meminfo"]);
        $docker = self::first(['docker', 'info', '--format', '{{.NCPU}} {{.MemTotal}} {{.OperatingSystem}}']);
        $load = sys_getloadavg();
        $lines = [sprintf(
            'Machine: %s, %s cores, %s memory, load %s.',
            trim($cpu),
            $cores,
            $bytes !== null && ctype_digit($bytes) ? self::gibibytes((int) $bytes) : 'unknown',
            $load === false ? 'unknown' : implode(' ', array_map(static fn (float $each): string => sprintf('%.1f', $each), $load)),
        )];

        if ($docker !== null && preg_match('/\A([0-9]+) ([0-9]+) (.+)\z/', $docker, $parts) === 1) {
            $lines[] = sprintf('Docker: %s CPUs, %s memory, %s.', $parts[1], self::gibibytes((int) $parts[2]), $parts[3]);
        }

        return $lines;
    }

    private static function gibibytes(int $bytes): string
    {
        return sprintf('%.1f GiB', $bytes / 1024 ** 3);
    }

    /**
     * @param  list<string>  $command
     */
    private static function first(array $command): ?string
    {
        $process = new Process($command);
        $process->setTimeout(10);

        try {
            $process->run();
        } catch (Throwable) {
            return null;
        }

        $output = trim($process->getOutput());

        return $process->isSuccessful() && $output !== '' ? strtok($output, "\n") ?: null : null;
    }
}
