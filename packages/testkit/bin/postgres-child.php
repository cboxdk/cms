<?php

declare(strict_types=1);

/*
 * Entry point of a child process started by Cbox\Cms\Testkit\Postgres\ChildProcesses.
 *
 * The parent passes the path of its Composer autoloader as the only argument, so the child
 * loads the same classes, and the payload on standard input. The work is in ChildProcessMain.
 * The file declares the testkit's namespace, because ChildProcessMain is internal to Cbox\Cms.
 */

namespace Cbox\Cms\Testkit\Postgres;

$autoload = $argv[1] ?? '';

if (! is_file($autoload)) {
    fwrite(STDERR, "Usage: php postgres-child.php <path to vendor/autoload.php>, with the payload on standard input.\n");

    exit(2); // ChildProcessMain::INVALID; the class is not loadable yet.
}

require $autoload;

exit(ChildProcessMain::run(
    (string) stream_get_contents(STDIN),
    static function (string $line): void {
        fwrite(STDOUT, $line);
        fflush(STDOUT);
    },
    static function (string $text): void {
        fwrite(STDERR, $text);
    },
));
