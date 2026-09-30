<?php

declare(strict_types=1);

/*
 * Runs a command for this checkout in the php-baseimages dev image, the image CI runs in
 * (DevImage, DevImageRun):
 *
 *   php tools/bin/dev-image.php -- <command> [<argument>...]
 *   composer image:run -- <command> [<argument>...]
 *
 *   composer image:run -- vendor/bin/pest --testsuite=Browser
 *
 * From the host it starts a container of the image for the checkout of the working directory, a
 * linked worktree included, mounted at the same path, as the host user, on the network of the
 * shared services, which must run (`composer services:up` in the main checkout); it never starts
 * them. In the image already, such as in the php service of compose.yaml or in CI, it runs the
 * command in place.
 *
 * Exits with the command's exit code, 1 when the run cannot start, and 2 on a usage error.
 */

use Cbox\Cms\Tooling\Check\Boundary\CommandLine;
use Cbox\Cms\Tooling\DevImage\Adapter\DockerDevImage;
use Cbox\Cms\Tooling\DevImage\Domain\DevImage;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$arguments = CommandLine::arguments();

if (($arguments[0] ?? null) === '--') {
    array_shift($arguments);
}

if ($arguments === []) {
    fwrite(STDERR, "Usage: php tools/bin/dev-image.php -- <command> [<argument>...]\n");
    exit(2);
}

if (! DevImage::runsIn(getenv(DevImage::TIER_VARIABLE))) {
    exit(new DockerDevImage()->run((string) getcwd(), $arguments));
}

$process = proc_open($arguments, [STDIN, STDOUT, STDERR], $pipes);

exit($process === false ? 127 : proc_close($process));
