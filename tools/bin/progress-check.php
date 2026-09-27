<?php

declare(strict_types=1);

/*
 * `composer progress:check`: whether PROGRESS.md and CHECKS-LOG.md record a task before the merge
 * queue moves main.
 *
 *   php tools/bin/progress-check.php <block>-<task> [--changed-checks] [--range=<revision range>]
 *
 * It reads PROGRESS.md and CHECKS-LOG.md of this checkout and requires an entry for the task under
 * "Kontroller kørt" in PROGRESS.md; with --changed-checks also an entry for it in CHECKS-LOG.md,
 * under the heading of its block, that says GUARDRAILS 7.3; and with --range, such as
 * --range=main..HEAD, that no commit of the range changes nothing
 * (Cbox\Cms\Tooling\Progress\Domain\ProgressAudit). It prints each problem.
 *
 * Exits 0 when the task is recorded, 1 when it is not or a file or the range cannot be read, and 2
 * on a usage error.
 */

use Cbox\Cms\Tooling\Check\Boundary\CommandLine;
use Cbox\Cms\Tooling\Progress\Boundary\GitEmptyCommits;
use Cbox\Cms\Tooling\Progress\Boundary\ProgressCheckOptions;
use Cbox\Cms\Tooling\Progress\Domain\ChecksLog;
use Cbox\Cms\Tooling\Progress\Domain\ProgressAudit;
use Cbox\Cms\Tooling\Progress\Domain\ProgressLedger;

$root = (string) realpath(dirname(__DIR__, 2));

require $root.'/vendor/autoload.php';

try {
    $options = ProgressCheckOptions::parse(CommandLine::arguments());
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage()."\n".ProgressCheckOptions::USAGE."\n");
    exit(2);
}

$markdown = [];

foreach (['PROGRESS.md', ChecksLog::FILE] as $file) {
    $contents = is_file($root.'/'.$file) ? file_get_contents($root.'/'.$file) : false;

    if ($contents === false) {
        fwrite(STDERR, "Cannot read {$root}/{$file}.\n");
        exit(1);
    }

    $markdown[$file] = $contents;
}

try {
    $emptyCommits = $options->range === null ? [] : GitEmptyCommits::in($root, $options->range);
} catch (UnexpectedValueException $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}

$problems = ProgressAudit::problems(
    ProgressLedger::fromMarkdown($markdown['PROGRESS.md']),
    ChecksLog::fromMarkdown($markdown[ChecksLog::FILE]),
    $options->task,
    $options->changedChecks,
    $emptyCommits,
);

foreach ($problems as $problem) {
    fwrite(STDERR, $problem."\n");
}

if ($problems !== []) {
    exit(1);
}

fwrite(STDOUT, "PROGRESS.md records {$options->task->value}".($options->changedChecks ? ', '.ChecksLog::FILE.' its changed checks' : '').($options->range === null ? '' : ", and no commit of {$options->range} is empty").".\n");

exit(0);
