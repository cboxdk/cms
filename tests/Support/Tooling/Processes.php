<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Tooling;

use Symfony\Component\Process\Process;

/**
 * Asks ps which processes still run, for the tests of steps in a process group of their own. A
 * zombie does not count: it has ended and holds nothing open, and where PID 1 does not reap
 * orphans, as in a GitHub job container, it stays in the table.
 */
final readonly class Processes
{
    /**
     * @param  list<int>  $pids
     * @return list<int> the given processes that are running
     */
    public static function running(array $pids): array
    {
        if ($pids === []) {
            return [];
        }

        $ps = new Process(['ps', '-o', 'pid=,stat=', '-p', implode(',', $pids)]);
        $ps->run();
        $running = [];

        foreach (explode("\n", trim($ps->getOutput())) as $line) {
            if (preg_match('/^\s*(\d+)\s+(\S+)/', $line, $match) === 1 && ! str_starts_with($match[2], 'Z')) {
                $running[] = (int) $match[1];
            }
        }

        return $running;
    }

    /**
     * The process ids in a file, one per line.
     *
     * @return list<int>
     */
    public static function idsIn(string $file): array
    {
        $text = is_file($file) ? (string) file_get_contents($file) : '';

        return array_values(array_map(intval(...), array_filter(explode("\n", $text), ctype_digit(...))));
    }
}
