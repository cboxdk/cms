<?php

declare(strict_types=1);

/*
 * Fixture for ProcessGroupTest, run as its own PHP process: runs one command in a process group
 * of its own through SymfonyProcessRunner, as the Browser step of gate 8 runs. The command starts
 * a sleep in the background, writes its process id to the file in the first argument and waits,
 * so the test can signal this runner while the step runs.
 */

use Cbox\Cms\Tooling\Check\Adapter\SymfonyProcessRunner;

require dirname(__DIR__, 4).'/vendor/autoload.php';

$file = $argv[1] ?? '';

$outcome = new SymfonyProcessRunner(60.0)->run(
    ['sh', '-c', 'sleep 60 & echo $! > "$1"; wait', 'sh', $file],
    getcwd() ?: '.',
    ownProcessGroup: true,
);

echo "step ended: {$outcome->exitCode}\n";
