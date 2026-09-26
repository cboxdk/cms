<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Fixtures;

use RuntimeException;
use Symfony\Component\Process\Process;

/*
 * Fixture for BrowserStepTest, run in a separate Pest process. It is not a suite file (no
 * Test.php suffix). The browser plugin treats a test whose closure calls visit() as a browser
 * test and starts the Playwright server before it, as browser-plugin-boot.php shows; the closure
 * is never called, so no browser is launched. The test writes the process ids of the Playwright
 * processes below this Pest process to the file in CMS_BROWSER_FATAL_PIDS, and then dies:
 * with CMS_BROWSER_FATAL_HOW=killed of SIGKILL, as the kernel ends a process at a container's
 * memory limit, so neither Pest nor the plugin can stop the server; otherwise of PHP's fatal
 * error for exhausted memory, after which Pest's shutdown handler still stops it.
 */

it('fixture: dies of a fatal error while the Playwright server runs', function (): void {
    expect(static fn (): mixed => visit('/'))->toBeCallable();

    $file = getenv('CMS_BROWSER_FATAL_PIDS');

    if (! is_string($file) || $file === '') {
        throw new RuntimeException('Set CMS_BROWSER_FATAL_PIDS to the file for the process ids.');
    }

    $ps = new Process(['ps', '-A', '-o', 'pid=,ppid=,command=']);
    $ps->mustRun();

    $parents = [];
    $commands = [];

    foreach (explode("\n", trim($ps->getOutput())) as $line) {
        if (preg_match('/^\s*(\d+)\s+(\d+)\s+(.*)$/', $line, $match) === 1) {
            $parents[(int) $match[1]] = (int) $match[2];
            $commands[(int) $match[1]] = $match[3];
        }
    }

    $below = [posix_getpid()];

    do {
        $count = count($below);

        foreach ($parents as $pid => $parent) {
            if (in_array($parent, $below, true) && ! in_array($pid, $below, true)) {
                $below[] = $pid;
            }
        }
    } while (count($below) > $count);

    $playwright = array_filter(
        array_slice($below, 1),
        static fn (int $pid): bool => str_contains($commands[$pid] ?? '', 'playwright'),
    );

    file_put_contents($file, implode("\n", $playwright));

    if (getenv('CMS_BROWSER_FATAL_HOW') === 'killed') {
        posix_kill(posix_getpid(), SIGKILL);
    }

    ini_set('memory_limit', (string) (memory_get_usage(true) + 8 * 1024 * 1024));
    $blocks = [];

    // Grows until PHP stops the process with the fatal error for exhausted memory.
    for ($block = 0; $block < PHP_INT_MAX; $block++) {
        $blocks[] = str_repeat('x', 64);
    }
});
