<?php

declare(strict_types=1);

/*
 * `composer check:selftest`: plants one known violation per gate in a temporary git worktree of
 * HEAD, runs `composer check` there and asserts that each gate catches its violation with a path
 * inside the worktree, then removes the worktree. Exits 0 when every violation was caught and
 * the worktree is gone.
 */

use Cbox\Cms\Tooling\Check\Adapter\SymfonyProcessRunner;
use Cbox\Cms\Tooling\Check\Boundary\CommandLine;
use Cbox\Cms\Tooling\Check\Boundary\ComposerCommand;
use Cbox\Cms\Tooling\Selftest\Adapter\GateSelftest;

$root = (string) realpath(dirname(__DIR__, 2));

require $root.'/vendor/autoload.php';

if (CommandLine::arguments() !== []) {
    fwrite(STDERR, "Usage: composer check:selftest\n");
    exit(2);
}

exit(new GateSelftest(new SymfonyProcessRunner, ComposerCommand::resolve(PHP_BINARY), STDOUT)->run($root));
