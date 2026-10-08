<?php

declare(strict_types=1);

/*
 * `composer workbench:serve`: serves the workbench, with the panel at /cms, in a container of the
 * php-baseimages dev image for this checkout (WorkbenchServe, DevImageRun):
 *
 *   php tools/bin/workbench-serve.php [--port=<host port>]
 *   composer workbench:serve [-- --port=<host port>]
 *
 * The container runs `vendor/bin/testbench serve` on port 8080 of every interface of the container,
 * publishes it on the host's 127.0.0.1, on 8080 unless --port names another, and reaches Postgres
 * and Valkey on the network of the shared services, which must run (`composer services:up` in the
 * main checkout); it never starts them. First it checks that workbench/.env has an APP_KEY, that
 * Testbench's application reads the same file, and that the panel has a build, and says how to fix
 * what is missing. In the image already, it runs the
 * server in place. Ctrl-C stops the server and removes the container.
 *
 * Exits with the server's exit code, 1 when something is missing or the run cannot start, and 2 on
 * a usage error.
 */

use Cbox\Cms\Tooling\Check\Boundary\CommandLine;
use Cbox\Cms\Tooling\DevImage\Adapter\DockerDevImage;
use Cbox\Cms\Tooling\DevImage\Domain\DevImage;
use Cbox\Cms\Tooling\Workbench\Boundary\WorkbenchServeOptions;
use Cbox\Cms\Tooling\Workbench\Domain\WorkbenchEnvironment;
use Cbox\Cms\Tooling\Workbench\Domain\WorkbenchServe;

require dirname(__DIR__, 2).'/vendor/autoload.php';

// Composer runs a script from the root of the checkout.
$root = (string) getcwd();

try {
    $serve = WorkbenchServeOptions::parse(CommandLine::arguments());
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage()."\n".WorkbenchServeOptions::USAGE."\n");
    exit(2);
}

$settings = $root.'/'.WorkbenchEnvironment::FILE;
$application = $root.'/'.WorkbenchEnvironment::APPLICATION_FILE;
$problems = WorkbenchServe::problems(
    is_file($settings) ? (string) file_get_contents($settings) : null,
    is_file($application) ? (string) file_get_contents($application) : null,
    is_file($root.'/'.WorkbenchServe::PANEL_MANIFEST),
);

if ($problems !== []) {
    foreach ($problems as $problem) {
        fwrite(STDERR, 'workbench:serve: '.$problem."\n");
    }

    exit(1);
}

if (DevImage::runsIn(getenv(DevImage::TIER_VARIABLE))) {
    $process = proc_open($serve->command(), [STDIN, STDOUT, STDERR], $pipes, $root);

    exit($process === false ? 127 : proc_close($process));
}

fwrite(STDOUT, sprintf("workbench:serve: the panel is at %s once the server runs; Ctrl-C stops it.\n", $serve->panelUrl()));

exit(new DockerDevImage()->run($root, $serve->command(), [], $serve->port));
