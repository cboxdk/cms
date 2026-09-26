<?php

declare(strict_types=1);

/*
 * `php tools/bin/process-group.php <program> [<argument>...]` runs a command as the leader of a
 * new process group. SymfonyProcessRunner starts a step that asks for a process group of its own
 * through it, such as the Browser suite of gate 8, so every process the command starts, such as
 * the browser plugin's `playwright run-server`, stays in a group the runner can kill when the step
 * ends. The script replaces itself with the command, which keeps its process id, and that id is
 * the id of the group. A program without a slash is looked up in PATH, as a shell does.
 *
 * Exit codes of its own: 2 without a command, 126 when the group cannot be made or the program
 * cannot be run, 127 when the program is not found.
 */

$arguments = $_SERVER['argv'] ?? [];
$command = is_array($arguments) ? array_values(array_filter(array_slice($arguments, 1), is_string(...))) : [];

if ($command === []) {
    fwrite(STDERR, "Usage: php tools/bin/process-group.php <program> [<argument>...]\n");
    exit(2);
}

$program = $command[0];

if (! str_contains($program, '/')) {
    $path = getenv('PATH');
    $found = null;

    foreach (explode(':', is_string($path) ? $path : '') as $directory) {
        $candidate = ($directory === '' ? '.' : $directory).'/'.$program;

        if (is_file($candidate) && is_executable($candidate)) {
            $found = $candidate;
            break;
        }
    }

    if ($found === null) {
        fwrite(STDERR, "process-group: {$program} is not in PATH.\n");
        exit(127);
    }

    $program = $found;
}

if (! posix_setpgid(0, 0)) {
    fwrite(STDERR, 'process-group: cannot start a process group: '.posix_strerror(posix_get_last_error())."\n");
    exit(126);
}

pcntl_exec($program, array_slice($command, 1));

fwrite(STDERR, "process-group: cannot run {$program}: ".pcntl_strerror(pcntl_get_last_error())."\n");
exit(126);
