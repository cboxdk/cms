<?php

declare(strict_types=1);

/*
 * `composer check`: the local profile of GUARDRAILS 10, gates 1 to 6 on this checkout. It runs
 * every gate, also after a failure, prints each gate and step as pass, fail or not run, and exits
 * 1 when a gate fails. Options: --report=<file> writes the report as JSON, --brief leaves the
 * output of failed steps out of the console, --pr runs the PR profile as CI runs it
 * (bin/ci): the same steps, mutation on changed files in gate 5, gates 8, 9 and 10, and the gates
 * CI does not run yet reported as not run. Mutation on changed files mutates what changed since the
 * merge base of CMS_CI_BASE_REF and HEAD; without that variable its step fails.
 */

use Cbox\Cms\Tooling\Check\Adapter\ConsoleListener;
use Cbox\Cms\Tooling\Check\Adapter\SymfonyProcessRunner;
use Cbox\Cms\Tooling\Check\Boundary\CheckOptions;
use Cbox\Cms\Tooling\Check\Boundary\CheckReportJson;
use Cbox\Cms\Tooling\Check\Boundary\CommandLine;
use Cbox\Cms\Tooling\Check\Boundary\ComposerCommand;
use Cbox\Cms\Tooling\Check\Domain\CheckRunner;
use Cbox\Cms\Tooling\Check\Domain\ReportFormatter;
use Cbox\Cms\Tooling\Mutation\Boundary\GitMutationScope;

$root = (string) realpath(dirname(__DIR__, 2));

require $root.'/vendor/autoload.php';

try {
    $options = CheckOptions::parse(CommandLine::arguments());
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(2);
}

$listener = new ConsoleListener(STDOUT, $options->brief);
$listener->write(ReportFormatter::header($root, $options->profile));

$baseRef = getenv(GitMutationScope::VARIABLE);
$mutation = $options->profile->mutates() ? GitMutationScope::resolve($root, $baseRef === false ? null : $baseRef) : null;
$gates = $options->profile->gates(PHP_BINARY, ComposerCommand::resolve(PHP_BINARY), $mutation);
$report = new CheckRunner(new SymfonyProcessRunner, $listener)->run($gates, $root);

$listener->write(ReportFormatter::summary($report));

if ($options->reportFile !== null && file_put_contents($options->reportFile, CheckReportJson::encode($report)) === false) {
    fwrite(STDERR, "Cannot write the report to {$options->reportFile}.\n");
    exit(1);
}

exit($report->passed() ? 0 : 1);
