<?php

declare(strict_types=1);

/*
 * `composer mutation:plan`: how mutation on changed files is split into shards for this checkout
 * (Cbox\Cms\Tooling\Mutation\Domain\MutationShards). It finds the changed sources since the base
 * of the change as `composer check -- --pr` does (GitMutationScope, from CMS_CI_BASE_REF), prints
 * each shard with its files, and with --output writes the plan as JSON for the verdict, with
 * --github-output the shard jobs' matrix as step outputs of GitHub Actions.
 *
 * Exits 0 when it made a plan, also one whose base is missing (each shard then fails with the
 * reason), 1 when a file cannot be written, and 2 on a usage error.
 */

use Cbox\Cms\Tooling\Check\Boundary\CommandLine;
use Cbox\Cms\Tooling\Mutation\Boundary\GitMutationScope;
use Cbox\Cms\Tooling\Mutation\Boundary\MutationPlanJson;
use Cbox\Cms\Tooling\Mutation\Boundary\MutationPlanOptions;
use Cbox\Cms\Tooling\Mutation\Domain\ChangedSource;
use Cbox\Cms\Tooling\Mutation\Domain\MutationShards;

$root = (string) realpath(dirname(__DIR__, 2));

require $root.'/vendor/autoload.php';

try {
    $options = MutationPlanOptions::parse(CommandLine::arguments());
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(2);
}

$baseRef = getenv(GitMutationScope::VARIABLE);
$plan = MutationShards::plan(GitMutationScope::resolve($root, $baseRef === false ? null : $baseRef));

echo $plan->failure === null
    ? sprintf("mutation:plan: %d changed files since %s in %d shards of at most %d files\n", count($plan->sources()), $plan->base, $plan->count(), MutationShards::FILES_PER_SHARD)
    : "mutation:plan: no base of the change, so every shard fails: {$plan->failure}\n";

foreach ($plan->shards as $shard) {
    $postgres = count(array_filter($shard->sources, static fn (ChangedSource $source): bool => $source->needsPostgres()));
    printf(
        "  %s: %d files, %d bytes, %d in Adapter or Infrastructure\n",
        $shard->label(),
        count($shard->sources),
        array_sum(array_map(static fn (ChangedSource $source): int => $source->size, $shard->sources)),
        $postgres,
    );
}

if ($options->output !== null && file_put_contents($options->output, MutationPlanJson::encode($plan)) === false) {
    fwrite(STDERR, "mutation:plan: cannot write {$options->output}.\n");
    exit(1);
}

if ($options->githubOutput !== null && file_put_contents($options->githubOutput, sprintf("count=%d\nshards=%s\n", $plan->count(), MutationPlanJson::matrix($plan)), FILE_APPEND) === false) {
    fwrite(STDERR, "mutation:plan: cannot write {$options->githubOutput}.\n");
    exit(1);
}

exit(0);
