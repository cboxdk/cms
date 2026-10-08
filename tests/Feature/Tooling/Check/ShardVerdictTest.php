<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Check;

use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\ParallelWorker;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Cbox\Cms\Tooling\Check\Boundary\ShardReportJson;
use Cbox\Cms\Tooling\Check\Boundary\ShardVerdictOptions;
use Cbox\Cms\Tooling\Check\Domain\ShardPlan;
use Cbox\Cms\Tooling\Check\Domain\ShardReport;
use Cbox\Cms\Tooling\Check\Domain\ShardVerdict;
use Cbox\Cms\Tooling\Mutation\Domain\JobResult;
use InvalidArgumentException;
use Symfony\Component\Process\Process;

/*
 * The verdict over the parts of a CI run whose sharded suites, the Postgres suite of gate 5 and
 * the Browser suite of gate 8, ran in the shards of the declared plan (ShardPlan): it fails unless
 * the gates part and the shard jobs passed and every shard of the plan reported once, having run
 * every sharded step, so a gate is never dropped because a job did not run (GUARDRAILS 10). The
 * reports here are planted.
 */

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

/**
 * A report per shard of the plan, each having run every sharded step.
 *
 * @return list<ShardReport>
 */
function shardReports(bool $passed = true): array
{
    return array_map(
        static fn (int $index): ShardReport => new ShardReport($index, ShardPlan::SHARDS, $passed, ShardPlan::names()),
        ShardPlan::indexes(),
    );
}

/**
 * Runs `composer shards:verdict` as the verdict job does, on a directory of planted artifacts.
 *
 * @param  list<ShardReport>  $reports
 */
function runShardVerdictScript(array $reports, string $gates = 'success', string $shards = 'success', string $extraFile = ''): Process
{
    $artifacts = ScratchDirectory::make();

    foreach ($reports as $report) {
        ScratchDirectory::write("{$artifacts}/suite-shard-{$report->index}/".ShardReportJson::FILE_NAME, ShardReportJson::encode($report));
    }

    if ($extraFile !== '') {
        ScratchDirectory::write("{$artifacts}/suite-shard-9/".ShardReportJson::FILE_NAME, $extraFile);
    }

    $process = new Process([PHP_BINARY, 'tools/bin/shard-verdict.php', "--reports={$artifacts}", "--gates={$gates}", "--shards={$shards}"], Phpstan::root(), null, null, 60);
    $process->run();

    return $process;
}

it('passes only when the gates, the shard jobs and every shard of the plan passed', function (): void {
    $verdict = ShardVerdict::judge(shardReports(), JobResult::Success, JobResult::Success);

    expect($verdict->passed())->toBeTrue()
        ->and($verdict->reports)->toHaveCount(ShardPlan::SHARDS)
        ->and($verdict->failures)->toBe([])
        ->and($verdict->lines())->toContain(
            'pass  shard 1 of '.ShardPlan::SHARDS.' ran Postgres, Browser',
            ShardPlan::SHARDS.' of '.ShardPlan::SHARDS.' shards reported, each running Postgres and Browser',
            'verdict: pass',
        )
        ->and(ShardVerdict::judge(shardReports(passed: false), JobResult::Success, JobResult::Success)->failures)
        ->toContain('shard 1 of '.ShardPlan::SHARDS.' failed');
});

it('fails when a shard of the plan did not report, reported twice or reported another count than the plan', function (): void {
    $missing = ShardVerdict::judge(array_slice(shardReports(), 1), JobResult::Success, JobResult::Success);
    $twice = ShardVerdict::judge([...shardReports(), shardReports()[0]], JobResult::Success, JobResult::Success);
    $other = ShardVerdict::judge([new ShardReport(1, ShardPlan::SHARDS + 1, true, ShardPlan::names())], JobResult::Success, JobResult::Success);

    expect($missing->passed())->toBeFalse()
        ->and($missing->failures)->toBe(['shard 1 of '.ShardPlan::SHARDS.' did not report'])
        ->and($twice->passed())->toBeFalse()
        ->and($twice->failures)->toBe(['shard 1 of '.ShardPlan::SHARDS.' reported twice'])
        ->and($other->passed())->toBeFalse()
        ->and($other->failures[0] ?? null)->toBe('a report of shard 1 of '.(ShardPlan::SHARDS + 1).', but the plan has '.ShardPlan::SHARDS.' shards')
        ->and($other->failures)->toContain('shard 1 of '.ShardPlan::SHARDS.' did not report');
});

it('fails when a shard ran fewer than the plan\'s steps, so a sharded suite cannot be dropped', function (): void {
    $partial = [new ShardReport(1, ShardPlan::SHARDS, true, ['Postgres']), ...array_slice(shardReports(), 1)];
    $none = [new ShardReport(1, ShardPlan::SHARDS, true, []), ...array_slice(shardReports(), 1)];

    expect(ShardVerdict::judge($partial, JobResult::Success, JobResult::Success)->failures)
        ->toBe(['shard 1 of '.ShardPlan::SHARDS.' ran Postgres, not Postgres and Browser'])
        ->and(ShardVerdict::judge($none, JobResult::Success, JobResult::Success)->failures)
        ->toBe(['shard 1 of '.ShardPlan::SHARDS.' ran none of the sharded steps, not Postgres and Browser'])
        ->and(ShardVerdict::judge($none, JobResult::Success, JobResult::Success)->lines())
        ->toContain('fail  shard 1 of '.ShardPlan::SHARDS.' ran none of the sharded steps');
});

it('fails when the gates or the shard jobs did not succeed, in GitHub\'s words', function (string $result): void {
    $gates = ShardVerdict::judge(shardReports(), JobResult::from($result), JobResult::Success);
    $shards = ShardVerdict::judge(shardReports(), JobResult::Success, JobResult::from($result));

    expect($gates->passed())->toBeFalse()
        ->and($gates->failures)->toBe(["the gates ended {$result}"])
        ->and($shards->passed())->toBeFalse()
        ->and($shards->failures)->toBe(["the shard jobs ended {$result}"]);
})->with(['failure', 'cancelled', 'skipped']);

it('reads and writes a shard\'s report, and refuses one that is not in the format', function (): void {
    $report = new ShardReport(2, ShardPlan::SHARDS, true, ShardPlan::names());
    $json = ShardReportJson::encode($report);

    expect(ShardReportJson::decode($json))->toEqual($report)
        ->and($json)->toContain('"format": 1', '"index": 2', '"passed": true', '"Postgres"')
        ->and(ShardReportJson::FILE_NAME)->toBe('suite-shard.json')
        ->and(static fn (): ShardReport => ShardReportJson::decode('{"format": 7}'))->toThrow(InvalidArgumentException::class, 'not in format 1')
        ->and(static fn (): ShardReport => ShardReportJson::decode('{'))->toThrow(InvalidArgumentException::class, 'not JSON')
        ->and(static fn (): ShardReport => ShardReportJson::decode('{"format": 1, "index": 1, "count": 4, "passed": true, "steps": [3]}'))->toThrow(InvalidArgumentException::class, 'is a name')
        ->and(static fn (): ShardReport => new ShardReport(0, 4, true, []))->toThrow(InvalidArgumentException::class, 'is not a shard')
        ->and(static fn (): ShardReport => new ShardReport(5, 4, true, []))->toThrow(InvalidArgumentException::class, 'is not a shard');
});

it('takes the reports, the gates and the shards as options and refuses anything else', function (): void {
    $options = ShardVerdictOptions::parse(['--reports=/tmp/artifacts', '--gates=success', '--shards=failure']);

    expect($options->reports)->toBe('/tmp/artifacts')
        ->and($options->gates)->toBe(JobResult::Success)
        ->and($options->shards)->toBe(JobResult::Failure)
        ->and(static fn (): ShardVerdictOptions => ShardVerdictOptions::parse(['--reports=/tmp']))->toThrow(InvalidArgumentException::class, '--gates is missing')
        ->and(static fn (): ShardVerdictOptions => ShardVerdictOptions::parse(['--reports=/tmp', '--gates=success', '--shards=maybe']))->toThrow(InvalidArgumentException::class, '--shards=maybe is not a result')
        ->and(static fn (): ShardVerdictOptions => ShardVerdictOptions::parse(['--plan=/tmp']))->toThrow(InvalidArgumentException::class, 'Unknown or repeated option --plan=/tmp')
        ->and(ShardVerdictOptions::USAGE)->toContain('composer shards:verdict', '--reports=<dir>', '--gates=<result>', '--shards=<result>');
});

it('exits 0 from composer shards:verdict when every shard of the plan reported and passed', function (): void {
    $process = runShardVerdictScript(shardReports());

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput())
        ->and($process->getOutput())->toContain(
            'shards:verdict: '.ShardPlan::SHARDS.' shards of the Postgres and Browser suites, gates success, shard jobs success, '.ShardPlan::SHARDS.' reports',
            "verdict: pass\n",
        );
});

it('exits 1 from composer shards:verdict when a shard is missing, a job failed or a report cannot be read, and 2 on a usage error', function (): void {
    $missing = runShardVerdictScript(array_slice(shardReports(), 1));
    $failed = runShardVerdictScript(shardReports(), gates: 'failure');
    $unreadable = runShardVerdictScript(shardReports(), extraFile: '{"format": 7}');
    $usage = new Process([PHP_BINARY, 'tools/bin/shard-verdict.php', '--reports=x'], Phpstan::root());
    $usage->run();

    expect($missing->getExitCode())->toBe(1)
        ->and($missing->getOutput())->toContain('failed: shard 1 of '.ShardPlan::SHARDS.' did not report', "verdict: fail\n")
        ->and($failed->getExitCode())->toBe(1)
        ->and($failed->getOutput())->toContain('failed: the gates ended failure', "verdict: fail\n")
        ->and($unreadable->getExitCode())->toBe(1)
        ->and($unreadable->getOutput())->toContain('failed: unreadable shard report ', 'not in format 1', "verdict: fail\n")
        ->and($usage->getExitCode())->toBe(2)
        ->and($usage->getErrorOutput())->toContain('--gates is missing');
});

it('exits 1 from composer shards:verdict when no shard reported at all, never 0', function (): void {
    $process = runShardVerdictScript([]);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toContain('0 reports', 'failed: shard 1 of '.ShardPlan::SHARDS.' did not report', "verdict: fail\n");
});

it('is a Composer script of its own, which bin/ci runs for the verdict part', function (): void {
    $composer = json_decode((string) file_get_contents(Phpstan::root().'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $scripts = is_array($composer) && is_array($composer['scripts'] ?? null) ? $composer['scripts'] : [];

    expect($scripts['shards:verdict'] ?? null)->toBe('@php tools/bin/shard-verdict.php')
        ->and(is_file(Phpstan::root().'/tools/bin/shard-verdict.php'))->toBeTrue()
        ->and((string) file_get_contents(Phpstan::root().'/bin/ci'))->toContain('composer shards:verdict -- --reports="$1" --gates="$2" --shards="$3"');
});

/**
 * The test classes one Pest run of a suite would run, with or without a shard, sorted: Pest shards
 * a suite by test class, so these are what a shard runs.
 *
 * @return list<string>
 */
function listedClasses(string $suite, ?int $shard = null): array
{
    $command = [PHP_BINARY, 'vendor/bin/pest', '--testsuite='.$suite, ...($shard === null ? [] : [ShardPlan::option($shard, ShardPlan::SHARDS)]), '--list-tests'];
    $process = new Process($command, Phpstan::root(), ParallelWorker::cleared(), null, 300);
    $process->mustRun();
    preg_match_all('/ - (?:P\\\\)?([A-Za-z_]\w*(?:\\\\[A-Za-z_]\w*)*)::/', $process->getOutput(), $matches);
    $classes = array_values(array_unique($matches[1]));
    sort($classes, SORT_STRING);

    return $classes;
}

it('splits each sharded suite over the shards so every test class runs in exactly one of them, none twice and none left out', function (string $suite): void {
    $whole = listedClasses($suite);
    $shards = array_map(static fn (int $index): array => listedClasses($suite, $index), ShardPlan::indexes());
    $together = array_merge(...$shards);

    sort($together, SORT_STRING);

    expect($whole)->not->toBeEmpty()
        ->and($together)->toBe($whole)
        ->and(array_unique($together))->toHaveCount(count($whole));

    foreach ($shards as $index => $classes) {
        expect(count($classes))->toBeLessThan(count($whole), 'shard '.($index + 1).' of '.ShardPlan::SHARDS.' of the '.$suite.' suite runs the whole suite');
    }
})->with(ShardPlan::names());
