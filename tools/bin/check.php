<?php

declare(strict_types=1);

/*
 * `composer check`: the local profile of GUARDRAILS 10, gates 1 to 6 on this checkout. It runs
 * every gate, also after a failure, prints each gate and step as pass, fail or not run, and exits
 * 1 when a gate fails. Options: --report=<file> writes the report as JSON, --brief leaves the
 * output of failed steps out of the console.
 */

use Cbox\Cms\Tooling\Check\Adapter\ConsoleListener;
use Cbox\Cms\Tooling\Check\Adapter\SymfonyProcessRunner;
use Cbox\Cms\Tooling\Check\Boundary\CheckOptions;
use Cbox\Cms\Tooling\Check\Boundary\CheckReportJson;
use Cbox\Cms\Tooling\Check\Boundary\CommandLine;
use Cbox\Cms\Tooling\Check\Boundary\ComposerCommand;
use Cbox\Cms\Tooling\Check\Boundary\PhpunitSuites;
use Cbox\Cms\Tooling\Check\Domain\CheckRunner;
use Cbox\Cms\Tooling\Check\Domain\LocalProfile;
use Cbox\Cms\Tooling\Check\Domain\ReportFormatter;

$root = (string) realpath(dirname(__DIR__, 2));

require $root.'/vendor/autoload.php';

try {
    $options = CheckOptions::parse(CommandLine::arguments());
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(2);
}

$listener = new ConsoleListener(STDOUT, $options->brief);
$listener->write(ReportFormatter::header($root));

$gates = LocalProfile::gates(PHP_BINARY, ComposerCommand::resolve(PHP_BINARY), PhpunitSuites::in($root.'/phpunit.xml'));
$report = new CheckRunner(new SymfonyProcessRunner, $listener)->run($gates, $root);

$listener->write(ReportFormatter::summary($report));

if ($options->reportFile !== null && file_put_contents($options->reportFile, CheckReportJson::encode($report)) === false) {
    fwrite(STDERR, "Cannot write the report to {$options->reportFile}.\n");
    exit(1);
}

exit($report->passed() ? 0 : 1);
