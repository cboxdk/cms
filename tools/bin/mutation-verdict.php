<?php

declare(strict_types=1);

/*
 * `composer mutation:verdict`: the last job of CI, and the last part of bin/ci's containerized
 * run. It reads the plan and every shard's report below --reports (each file named
 * mutation-shard.json) and judges them with how the gates and the shard jobs ended
 * (Cbox\Cms\Tooling\Mutation\Domain\MutationVerdict): it passes only when the gates and every
 * shard passed, every shard of the plan reported once with its sources, and every changed class
 * reaches 80 % over all its mutations. It prints each class with its score and each failure.
 *
 * Exits 0 when the verdict passes, 1 when it fails or a file cannot be read, and 2 on a usage error.
 */

use Cbox\Cms\Tooling\Check\Boundary\CommandLine;
use Cbox\Cms\Tooling\Mutation\Boundary\MutationPlanJson;
use Cbox\Cms\Tooling\Mutation\Boundary\MutationShardReportJson;
use Cbox\Cms\Tooling\Mutation\Boundary\MutationVerdictOptions;
use Cbox\Cms\Tooling\Mutation\Domain\MutationVerdict;

$root = (string) realpath(dirname(__DIR__, 2));

require $root.'/vendor/autoload.php';

try {
    $options = MutationVerdictOptions::parse(CommandLine::arguments());
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(2);
}

$planText = is_file($options->plan) ? file_get_contents($options->plan) : false;

if ($planText === false) {
    fwrite(STDERR, "mutation:verdict: cannot read the plan {$options->plan}; verdict: fail\n");
    exit(1);
}

try {
    $plan = MutationPlanJson::decode($planText);
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, "mutation:verdict: {$options->plan}: {$exception->getMessage()}; verdict: fail\n");
    exit(1);
}

$reports = [];
$unreadable = [];
$files = is_dir($options->reports)
    ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($options->reports, FilesystemIterator::SKIP_DOTS))
    : [];
$paths = [];

foreach ($files as $file) {
    if ($file instanceof SplFileInfo && $file->getFilename() === MutationShardReportJson::FILE_NAME) {
        $paths[] = $file->getPathname();
    }
}

sort($paths, SORT_STRING);

foreach ($paths as $path) {
    try {
        $reports[] = MutationShardReportJson::decode((string) file_get_contents($path));
    } catch (InvalidArgumentException $exception) {
        $unreadable[] = "{$path}: {$exception->getMessage()}";
    }
}

$verdict = MutationVerdict::judge($plan, $reports, $options->gates, $options->shards);
$lines = $verdict->lines();

foreach ($unreadable as $problem) {
    array_splice($lines, -1, 0, ['failed: unreadable shard report '.$problem]);
}

$passed = $verdict->passed() && $unreadable === [];
$lines[count($lines) - 1] = $passed ? 'verdict: pass' : 'verdict: fail';

printf("mutation:verdict: %d shards of %s, gates %s, shard jobs %s, %d reports\n", $plan->count(), $plan->base ?? 'no base', $options->gates->value, $options->shards->value, count($paths));
echo implode("\n", $lines)."\n";

exit($passed ? 0 : 1);
