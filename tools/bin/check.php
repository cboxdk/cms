<?php

declare(strict_types=1);

/*
 * `composer check`: the local profile of GUARDRAILS 10, gates 1 to 6 on this checkout. It runs
 * every gate, also after a failure, prints each gate and step as pass, fail or not run, and exits
 * 1 when a gate fails. Options: --report=<file> writes the report as JSON, --brief leaves the
 * output of failed steps out of the console, --pr runs the PR profile as CI runs it
 * (bin/ci): the same steps, gates 7 to 10, and the gate CI does not run, 11, reported as not
 * run. --gate=<n>, repeated for more than one, runs only those gates of the profile and reports
 * the others as not run (GateSelection).
 *
 * CI runs the PR profile in parts, as parallel jobs, each with the budget of GUARDRAILS 10 to
 * itself. --pr --only=gates is the gates part: every gate but the suites the plan shards, the
 * Postgres suite of gate 5 and the Browser suite of gate 8, which it reports as run in the shard
 * jobs. --pr --shard=<i>/<n> is shard i of those suites (ShardPlan), with Pest's own --shard, and
 * reports the other gates as run in the gates job; --shard-report=<file> writes the shard's report
 * for the verdict (composer shards:verdict). --pr alone runs every gate in one run, the suites
 * whole.
 *
 * Mutation testing is deferred until after v1 (Sylvester, 2 October 2026): without --mutation,
 * gate 5 reports the Mutation suite and mutation on changed files as not run. With --pr --mutation
 * it runs both, and shards mutation on changed files instead of the suites: --only=gates then runs
 * the gates, the suites and the Mutation suite without mutation on changed files, and
 * --shard=<i>/<n> only shard i of the plan's n shards of it (MutationShards), as CI's jobs of a
 * run with mutation run them; --mutation-report=<file> writes that shard's report for the verdict
 * (composer mutation:verdict). Mutation on changed files mutates what changed since the merge base
 * of CMS_CI_BASE_REF and HEAD; when the variable is unset, empty or 40 zeros, it derives the base
 * from the checkout (GitMutationScope).
 *
 * Every step runs in the php-baseimages dev image, the image CI runs in (DevImage). Started on
 * the host, it checks the options and then runs itself again in a container of the image for this
 * checkout, a linked worktree included, mounted at the same path, as the host user and on the
 * network of the shared services (DockerDevImage), with the directory of the report mounted too.
 * In the image, as in CI, in the php service of compose.yaml and in that container, it runs the
 * gates in place.
 */

use Cbox\Cms\Tooling\Check\Adapter\ConsoleListener;
use Cbox\Cms\Tooling\Check\Adapter\SymfonyProcessRunner;
use Cbox\Cms\Tooling\Check\Boundary\CheckOptions;
use Cbox\Cms\Tooling\Check\Boundary\CheckReportJson;
use Cbox\Cms\Tooling\Check\Boundary\CommandLine;
use Cbox\Cms\Tooling\Check\Boundary\ComposerCommand;
use Cbox\Cms\Tooling\Check\Boundary\ShardReportJson;
use Cbox\Cms\Tooling\Check\Domain\CheckRunner;
use Cbox\Cms\Tooling\Check\Domain\GateSelection;
use Cbox\Cms\Tooling\Check\Domain\ReportFormatter;
use Cbox\Cms\Tooling\Check\Domain\ShardPlan;
use Cbox\Cms\Tooling\Check\Domain\ShardReport;
use Cbox\Cms\Tooling\DevImage\Adapter\DockerDevImage;
use Cbox\Cms\Tooling\DevImage\Domain\DevImage;
use Cbox\Cms\Tooling\Mutation\Boundary\GitMutationScope;
use Cbox\Cms\Tooling\Mutation\Boundary\MutationShardReportJson;
use Cbox\Cms\Tooling\Mutation\Domain\ClassTally;
use Cbox\Cms\Tooling\Mutation\Domain\MutationPlan;
use Cbox\Cms\Tooling\Mutation\Domain\MutationScope;
use Cbox\Cms\Tooling\Mutation\Domain\MutationShardReport;
use Cbox\Cms\Tooling\Mutation\Domain\MutationShards;
use Cbox\Cms\Tooling\Mutation\Domain\MutationTally;

$root = (string) realpath(dirname(__DIR__, 2));

require $root.'/vendor/autoload.php';

$arguments = CommandLine::arguments();

try {
    $options = CheckOptions::parse($arguments);
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(2);
}

if (! DevImage::runsIn(getenv(DevImage::TIER_VARIABLE))) {
    // The reports are written in the container: each an absolute path through the real
    // directory, which is mounted at the same path when it lies outside the checkout.
    $mounts = [];

    foreach (['--report=' => $options->reportFile, '--mutation-report=' => $options->mutationReportFile, '--shard-report=' => $options->shardReportFile] as $option => $file) {
        $directory = $file === null ? false : realpath(dirname(str_starts_with($file, '/') ? $file : getcwd().'/'.$file));

        if (is_string($directory)) {
            $mounts[] = $directory;
            $arguments = array_map(
                static fn (string $argument): string => str_starts_with($argument, $option) ? $option.$directory.'/'.basename((string) $file) : $argument,
                $arguments,
            );
        }
    }

    exit(new DockerDevImage()->run($root, ['php', 'tools/bin/check.php', ...$arguments], array_values(array_unique($mounts))));
}

$listener = new ConsoleListener(STDOUT, $options->brief);
$listener->write(ReportFormatter::header($root, $options->profile, $options->part, $options->gates));

$baseRef = getenv(GitMutationScope::VARIABLE);
$mutation = $options->profile->mutates() && $options->part->runsMutation() ? GitMutationScope::resolve($root, $baseRef === false ? null : $baseRef) : null;
$plan = $mutation instanceof MutationScope ? MutationShards::plan($mutation) : null;
$part = $options->part;

if ($plan instanceof MutationPlan && $part->isShard()) {
    // Shard i of n of the plan this checkout makes; a job given another count than the plan's
    // fails its mutation step, so no source is left out or mutated twice.
    $mutation = $part->shards === $plan->count()
        ? $plan->scope((int) $part->shard)
        : MutationScope::unresolved(sprintf('the job was given %d shards, but the plan of this checkout has %d (MutationShards)', (int) $part->shards, $plan->count()));
}

$tally = new MutationTally;
try {
    $gates = GateSelection::only($options->profile->gates(PHP_BINARY, ComposerCommand::resolve(PHP_BINARY), $mutation, $part, $tally), $options->gates);
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(2);
}

$report = new CheckRunner(new SymfonyProcessRunner, $listener)->run($gates, $root);

$listener->write(ReportFormatter::summary($report));

if ($options->reportFile !== null && file_put_contents($options->reportFile, CheckReportJson::encode($report)) === false) {
    fwrite(STDERR, "Cannot write the report to {$options->reportFile}.\n");
    exit(1);
}

if ($options->shardReportFile !== null) {
    $suiteShard = new ShardReport((int) $part->shard, (int) $part->shards, $report->passed(), ShardPlan::ran($report));

    if (file_put_contents($options->shardReportFile, ShardReportJson::encode($suiteShard)) === false) {
        fwrite(STDERR, "Cannot write the shard's report to {$options->shardReportFile}.\n");
        exit(1);
    }
}

if ($options->mutationReportFile !== null) {
    $shard = $plan instanceof MutationPlan && $part->shards === $plan->count() ? $plan->shard((int) $part->shard)->paths() : [];
    $shardReport = new MutationShardReport((int) $part->shard, (int) $part->shards, $shard, $report->passed(), array_values(array_filter(
        $tally->classes(),
        static fn (ClassTally $class): bool => in_array($class->path, $shard, true),
    )));

    if (file_put_contents($options->mutationReportFile, MutationShardReportJson::encode($shardReport)) === false) {
        fwrite(STDERR, "Cannot write the shard's report to {$options->mutationReportFile}.\n");
        exit(1);
    }
}

exit($report->passed() ? 0 : 1);
