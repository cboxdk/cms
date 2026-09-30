<?php

declare(strict_types=1);

/*
 * `composer scale:check -- [--entries=1000000] [--runs=11] [--profile=scale] [--seed=1]
 * [--sections=40] [--database=cms_scale] [--keep]`: the scale check of GUARDRAILS 4.3 and the M1
 * exit criterion, a listing under 20 ms in the database at a million entries (PRD 23).
 *
 * 1. It prints the machine: CPU, cores, memory, load and Docker's CPUs and memory.
 * 2. It (re)creates the dedicated scale database, cms_scale unless --database names another, as the
 *    owner role of phpunit.xml with the set-up of docker/postgres/sql/database.sql. It never uses
 *    the shared dev database or a test database. --keep keeps a scale database that exists, so a
 *    seed that stopped resumes where it stopped.
 * 3. It migrates it and runs cms:partitions:maintain through vendor/bin/testbench.
 * 4. It writes a site with --sections sections and a service actor granted on its root, with the
 *    testkit's fixture writers (Cbox\Cms\Tooling\Scale\Adapter\ScaleApplication).
 * 5. It runs `cms:seed-scale --profile --seed --entries` as that actor, and cms:partitions:maintain
 *    every minute while the seed runs, as the scheduler would, so the runway of the partitions on a
 *    sequence keeps ahead of the events. A seed that stops, such as a chunk that a loaded machine
 *    held past the command transaction's 5 seconds, is run again up to three times in all, and
 *    resumes its operation at the chunk that did not complete. It prints the seed's wall time.
 * 6. It runs ANALYZE, then the workbench listing --runs times with EXPLAIN (ANALYZE): the generated
 *    query builder's newest-first keyset page of 20 of the workbench's article type, the first
 *    page and the page after it, each the sum of its statements. It prints the median of each.
 *
 * Exits 0 when both medians are at most 20 ms, 1 when one is above or a step failed, and 2 on a
 * usage error.
 */

use Cbox\Cms\Testkit\Postgres\Boundary\TestDatabaseComment;
use Cbox\Cms\Testkit\Postgres\Infrastructure\PostgresTestDatabases;
use Cbox\Cms\Testkit\Postgres\Infrastructure\TestDatabaseSetup;
use Cbox\Cms\Testkit\Postgres\ServiceCheck;
use Cbox\Cms\Tooling\Check\Boundary\CommandLine;
use Cbox\Cms\Tooling\Scale\Adapter\ScaleApplication;
use Cbox\Cms\Tooling\Scale\Boundary\MachineDescription;
use Cbox\Cms\Tooling\Scale\Boundary\ScaleArguments;
use Cbox\Cms\Tooling\Scale\Domain\ScaleOptions;
use Cbox\Cms\Tooling\TestDatabase\Boundary\PhpunitDatabase;
use Symfony\Component\Process\Process;

$root = (string) realpath(dirname(__DIR__, 2));

require $root.'/vendor/autoload.php';

/** How often maintenance runs while the seed runs, in seconds. */
const MAINTAIN_EVERY_SECONDS = 60;

/** How often the seed runs before the check gives up: a run that stopped resumes its operation. */
const SEED_ATTEMPTS = 3;

$environment = getenv();
$variables = PhpunitDatabase::variables($root.'/phpunit.xml', $environment);
$owner = PhpunitDatabase::owner($root.'/phpunit.xml', $environment);
$workbench = is_file($root.'/workbench/.env') ? $root.'/workbench/.env' : $root.'/workbench/.env.example';
$dev = preg_match('/^DB_DATABASE=(\S+)$/m', (string) file_get_contents($workbench), $match) === 1 ? $match[1] : 'cms';

try {
    $options = ScaleArguments::parse(CommandLine::arguments(), $dev, $owner->database);
} catch (InvalidArgumentException $invalid) {
    fwrite(STDERR, $invalid->getMessage()."\n".ScaleArguments::USAGE."\n");
    exit(2);
}

foreach (MachineDescription::lines() as $line) {
    fwrite(STDOUT, $line."\n");
}

/**
 * Runs vendor/bin/testbench with the arguments on the scale database, printing its output.
 *
 * @param  list<string>  $arguments
 * @param  array<string, string>  $env
 */
function testbench(string $root, ScaleOptions $options, array $arguments, array $env = []): Process
{
    $process = new Process([PHP_BINARY, $root.'/vendor/bin/testbench', ...$arguments, '--no-ansi'], $root, ['DB_DATABASE' => $options->database, ...$env]);
    $process->setTimeout(null);

    return $process;
}

function step(string $root, ScaleOptions $options, string ...$arguments): void
{
    $process = testbench($root, $options, array_values($arguments));
    $process->run(static function (string $type, string $output): void {
        fwrite($type === Process::ERR ? STDERR : STDOUT, $output);
    });

    if (! $process->isSuccessful()) {
        fwrite(STDERR, sprintf("%s failed with exit %d.\n", implode(' ', $arguments), (int) $process->getExitCode()));
        exit(1);
    }
}

try {
    $databases = new PostgresTestDatabases($owner, ServiceCheck::CONNECT_TIMEOUT_SECONDS);

    if (! $options->keep && $databases->drop($options->database)) {
        fwrite(STDOUT, sprintf("Dropped the scale database %s.\n", $options->database));
    }

    $databases->provision(
        new TestDatabaseSetup($options->database, $owner->username, $variables['DB_USERNAME'] ?? 'cms_app', $owner->searchPath),
        TestDatabaseComment::of($root),
    );
} catch (Throwable $failure) {
    fwrite(STDERR, sprintf("Could not create the scale database %s as %s at %s:%d: %s\nRun composer services:up.\n", $options->database, $owner->username, $owner->host, $owner->port, $failure->getMessage()));
    exit(1);
}

fwrite(STDOUT, sprintf("Scale database %s at %s:%d.\n", $options->database, $owner->host, $owner->port));
step($root, $options, 'migrate', '--database=pgsql_owner', '--force');
step($root, $options, 'cms:partitions:maintain');

$application = ScaleApplication::boot($root, $options->database);
$actor = $application->world($options->sections);
fwrite(STDOUT, sprintf("Seeding %d entries with profile %s and seed %d as %s below %d sections.\n", $options->entries, $options->profile, $options->seed, $actor->toString(), $options->sections));

/**
 * Runs the seed once to the end, with maintenance every minute, and gives the process.
 */
function seed(string $root, ScaleOptions $options, string $actor): Process
{
    $seed = testbench($root, $options, ['cms:seed-scale', '--profile='.$options->profile, '--seed='.$options->seed, '--entries='.$options->entries], ['CBOX_CMS_SEEDING_SERVICE_ACTOR' => $actor]);
    $seed->start();
    $maintained = hrtime(true);

    while ($seed->isRunning()) {
        fwrite(STDOUT, $seed->getIncrementalOutput());
        fwrite(STDERR, $seed->getIncrementalErrorOutput());

        if (hrtime(true) - $maintained >= MAINTAIN_EVERY_SECONDS * 1_000_000_000) {
            step($root, $options, 'cms:partitions:maintain');
            $maintained = hrtime(true);
        }

        usleep(200_000);
    }

    fwrite(STDOUT, $seed->getIncrementalOutput());
    fwrite(STDERR, $seed->getIncrementalErrorOutput());

    return $seed;
}

$started = hrtime(true);

for ($attempt = 1; ; $attempt++) {
    $seed = seed($root, $options, $actor->toString());

    if ($seed->isSuccessful() || $attempt === SEED_ATTEMPTS) {
        break;
    }

    fwrite(STDOUT, sprintf("cms:seed-scale stopped with exit %d; resuming the operation where it stopped (attempt %d of %d).\n", (int) $seed->getExitCode(), $attempt + 1, SEED_ATTEMPTS));
}

$seconds = (hrtime(true) - $started) / 1e9;

if (! $seed->isSuccessful()) {
    fwrite(STDERR, sprintf("cms:seed-scale failed with exit %d after %.1f s.\n", (int) $seed->getExitCode(), $seconds));
    exit(1);
}

fwrite(STDOUT, sprintf("Seed wall time: %.1f s (%.0f entries per second).\n", $seconds, $options->entries / max($seconds, 0.001)));

$application->analyze();
[$first, $next, $rows] = $application->listing($actor, $options->runs);
fwrite(STDOUT, sprintf("The listing's first page holds %d rows.\n", $rows));

$passed = true;

foreach ([$first, $next] as $times) {
    fwrite(STDOUT, $times->line(ScaleOptions::BUDGET_MS)."\n");
    $passed = $passed && $times->within(ScaleOptions::BUDGET_MS);
}

exit($passed ? 0 : 1);
