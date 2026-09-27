<?php

declare(strict_types=1);

/*
 * `composer install:check`: the first step of gate 5. It fails when the installation in vendor/
 * is not the one composer.lock and composer.json describe, so a checkout that moved without
 * `composer install` (a pull, or a fast-forward of main by the merge queue) is reported as stale,
 * with the fix, instead of failing a suite at load with "Class not found".
 *
 *   php tools/bin/install-check.php [--root=<dir>]
 *
 * It compares the packages of composer.lock with vendor/composer/installed.json by name, version
 * and reference, and the root package's PSR-4, PSR-0 and files rules of composer.json's autoload
 * and autoload-dev with the autoloader Composer dumped (Cbox\Cms\Tooling\Install\Domain\InstallAudit),
 * and prints each difference on a line of its own.
 *
 * Exits 0 when the installation is current, 1 when it is not or a file cannot be read, and 2 on a
 * usage error.
 */

use Cbox\Cms\Tooling\Check\Boundary\CommandLine;
use Cbox\Cms\Tooling\Install\Boundary\ComposerInstallation;
use Cbox\Cms\Tooling\Install\Boundary\InstallCheckOptions;
use Cbox\Cms\Tooling\Install\Domain\InstallAudit;

$repository = (string) realpath(dirname(__DIR__, 2));

require $repository.'/vendor/autoload.php';

try {
    $options = InstallCheckOptions::parse(CommandLine::arguments());
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage()."\n".InstallCheckOptions::USAGE."\n");
    exit(2);
}

try {
    $installation = ComposerInstallation::at($options->root ?? $repository);
    $problems = InstallAudit::problems($installation->declared(), $installation->installed());
} catch (UnexpectedValueException $exception) {
    fwrite(STDERR, $exception->getMessage()."\n".InstallAudit::FIX."\n");
    exit(1);
}

foreach ($problems as $problem) {
    fwrite(STDOUT, $problem."\n");
}

if ($problems !== []) {
    fwrite(STDERR, sprintf("install:check: vendor/ is not the installation composer.lock and composer.json describe (%d %s), so the gates would check other code than CI. %s\n", count($problems), count($problems) === 1 ? 'difference' : 'differences', InstallAudit::FIX));
    exit(1);
}

fwrite(STDOUT, "install:check: vendor/ is the installation composer.lock and composer.json describe.\n");

exit(0);
