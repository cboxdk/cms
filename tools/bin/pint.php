<?php

declare(strict_types=1);

/*
 * `php tools/bin/pint.php [<argument>...]` runs vendor/bin/pint with the arguments, with the system
 * temp directory in the checkout's git-ignored .cache/pint/tmp. The Composer scripts lint and
 * lint:check run Pint through it.
 *
 * Pint compiles the Blade views of its console summary into the system temp directory (its
 * view.compiled setting is sys_get_temp_dir(), and pint.json cannot move it), so every checkout
 * and every other project on the machine would write the same file there. A coding agent never
 * sees it, because Pint prints JSON instead of the summary when it detects one; a terminal and CI
 * do. TMPDIR moves it, and whatever else Pint puts in the temp directory, into this checkout.
 *
 * The script replaces itself with Pint, which keeps its process id, its terminal and its exit code.
 * Exit code of its own: 126 when the directory cannot be made or Pint cannot be run.
 */

$root = dirname(__DIR__, 2);
$temporary = $root.'/.cache/pint/tmp';

if (! is_dir($temporary) && ! @mkdir($temporary, 0777, true) && ! is_dir($temporary)) {
    fwrite(STDERR, "pint: cannot create {$temporary}.\n");
    exit(126);
}

$arguments = $_SERVER['argv'] ?? [];
$arguments = is_array($arguments) ? array_values(array_filter(array_slice($arguments, 1), is_string(...))) : [];

// Composer's @php hands its memory limit on to the script; Pint gets the same.
$memoryLimit = ini_get('memory_limit');
$php = $memoryLimit !== '' ? ['-d', 'memory_limit='.$memoryLimit] : [];

pcntl_exec(PHP_BINARY, [...$php, $root.'/vendor/bin/pint', ...$arguments], [...getenv(), 'TMPDIR' => $temporary]);

fwrite(STDERR, 'pint: cannot run Pint: '.pcntl_strerror(pcntl_get_last_error())."\n");
exit(126);
