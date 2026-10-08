<?php

declare(strict_types=1);

/*
 * `composer shards:verdict`: the last job of CI, and the last part of bin/ci's containerized run,
 * for a run whose sharded suites ran in shards (the Postgres suite of gate 5 and the Browser suite
 * of gate 8; Cbox\Cms\Tooling\Check\Domain\ShardPlan). It reads every shard's report below
 * --reports (each file named suite-shard.json) and judges them with how the gates part and the
 * shard jobs ended (ShardVerdict): it passes only when the gates and every shard passed, every
 * shard of the plan reported exactly once, and each ran every sharded step. It prints each shard
 * with what it ran and each failure, so a dropped gate cannot pass as a missing report.
 *
 * Exits 0 when the verdict passes, 1 when it fails or a file cannot be read, and 2 on a usage error.
 */

use Cbox\Cms\Tooling\Check\Boundary\CommandLine;
use Cbox\Cms\Tooling\Check\Boundary\ShardReportJson;
use Cbox\Cms\Tooling\Check\Boundary\ShardVerdictOptions;
use Cbox\Cms\Tooling\Check\Domain\ShardPlan;
use Cbox\Cms\Tooling\Check\Domain\ShardVerdict;

$root = (string) realpath(dirname(__DIR__, 2));

require $root.'/vendor/autoload.php';

try {
    $options = ShardVerdictOptions::parse(CommandLine::arguments());
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(2);
}

$reports = [];
$unreadable = [];
$paths = [];
$files = is_dir($options->reports)
    ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($options->reports, FilesystemIterator::SKIP_DOTS))
    : [];

foreach ($files as $file) {
    if ($file instanceof SplFileInfo && $file->getFilename() === ShardReportJson::FILE_NAME) {
        $paths[] = $file->getPathname();
    }
}

sort($paths, SORT_STRING);

foreach ($paths as $path) {
    try {
        $reports[] = ShardReportJson::decode((string) file_get_contents($path));
    } catch (InvalidArgumentException $exception) {
        $unreadable[] = "{$path}: {$exception->getMessage()}";
    }
}

$verdict = ShardVerdict::judge($reports, $options->gates, $options->shards);
$lines = $verdict->lines();

foreach ($unreadable as $problem) {
    array_splice($lines, -1, 0, ['failed: unreadable shard report '.$problem]);
}

$passed = $verdict->passed() && $unreadable === [];
$lines[count($lines) - 1] = $passed ? 'verdict: pass' : 'verdict: fail';

printf(
    "shards:verdict: %d shards of %s, gates %s, shard jobs %s, %d reports\n",
    ShardPlan::SHARDS,
    ShardPlan::describe(),
    $options->gates->value,
    $options->shards->value,
    count($paths),
);
echo implode("\n", $lines)."\n";

exit($passed ? 0 : 1);
