<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Mutation;

use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Cbox\Cms\Tooling\Mutation\Boundary\MutationPlanJson;
use Cbox\Cms\Tooling\Mutation\Boundary\MutationShardReportJson;
use Cbox\Cms\Tooling\Mutation\Boundary\MutationVerdictOptions;
use Cbox\Cms\Tooling\Mutation\Domain\ChangedSource;
use Cbox\Cms\Tooling\Mutation\Domain\ClassTally;
use Cbox\Cms\Tooling\Mutation\Domain\JobResult;
use Cbox\Cms\Tooling\Mutation\Domain\MutationCount;
use Cbox\Cms\Tooling\Mutation\Domain\MutationPlan;
use Cbox\Cms\Tooling\Mutation\Domain\MutationScope;
use Cbox\Cms\Tooling\Mutation\Domain\MutationShard;
use Cbox\Cms\Tooling\Mutation\Domain\MutationShardReport;
use Cbox\Cms\Tooling\Mutation\Domain\MutationShards;
use Cbox\Cms\Tooling\Mutation\Domain\MutationVerdict;
use InvalidArgumentException;
use Symfony\Component\Process\Process;

/*
 * The verdict job of CI (M1-T66): mutation on changed files runs in shards, each a job of its
 * own, and the verdict fails unless the gates and every shard passed, every shard of the plan
 * reported once with its sources, and every changed class reaches 80 % over all its mutations.
 * The plans and reports here are planted.
 */

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

/**
 * A plan of three shards over six planted sources, two of them in Adapter.
 */
function verdictPlan(): MutationPlan
{
    $sources = [];

    foreach (['Alpha', 'Bravo', 'Charlie', 'Delta'] as $name) {
        $sources[] = new ChangedSource("packages/core/src/Planted/Domain/{$name}.php", "Cbox\\Cms\\Core\\Planted\\Domain\\{$name}", 1000);
    }

    foreach (['Echo', 'Foxtrot'] as $name) {
        $sources[] = new ChangedSource("packages/core/src/Planted/Adapter/{$name}.php", "Cbox\\Cms\\Core\\Planted\\Adapter\\{$name}", 1000);
    }

    $plan = MutationShards::plan(MutationScope::changed('abc123', $sources));

    return new MutationPlan($plan->base, null, [
        new MutationShard(1, 3, array_slice($plan->sources(), 2, 2)),
        new MutationShard(2, 3, array_slice($plan->sources(), 4, 2)),
        new MutationShard(3, 3, array_slice($plan->sources(), 0, 2)),
    ]);
}

/**
 * Each shard's report, with every class at the count $counts gives its short name (10 of 10 when
 * it is not listed), and passed unless the shard is listed in $failed.
 *
 * @param  array<string, array{int, int}>  $counts  mutations, caught
 * @param  list<int>  $failed
 * @return list<MutationShardReport>
 */
function verdictReports(MutationPlan $plan, array $counts = [], array $failed = []): array
{
    return array_map(static fn (MutationShard $shard): MutationShardReport => new MutationShardReport(
        $shard->index,
        $shard->count,
        $shard->paths(),
        ! in_array($shard->index, $failed, true),
        array_map(static function (ChangedSource $source) use ($counts): ClassTally {
            [$mutations, $caught] = $counts[basename($source->path, '.php')] ?? [10, 10];

            return new ClassTally($source->path, $source->name, new MutationCount($mutations, $caught));
        }, $shard->sources),
    ), $plan->shards);
}

it('passes when the gates and every shard passed and every class reaches 80 over its mutations, and lists each class', function (): void {
    $plan = verdictPlan();
    $verdict = MutationVerdict::judge($plan, verdictReports($plan, ['Bravo' => [5, 4], 'Echo' => [0, 0]]), JobResult::Success, JobResult::Success);

    expect($verdict->passed())->toBeTrue()
        ->and($verdict->failures)->toBe([])
        ->and($verdict->lines())->toBe([
            'pass  Cbox\Cms\Core\Planted\Adapter\Echo: no mutations',
            'pass  Cbox\Cms\Core\Planted\Adapter\Foxtrot: 100.00%, 10 of 10 mutations caught',
            'pass  Cbox\Cms\Core\Planted\Domain\Alpha: 100.00%, 10 of 10 mutations caught',
            'pass  Cbox\Cms\Core\Planted\Domain\Bravo: 80.00%, 4 of 5 mutations caught',
            'pass  Cbox\Cms\Core\Planted\Domain\Charlie: 100.00%, 10 of 10 mutations caught',
            'pass  Cbox\Cms\Core\Planted\Domain\Delta: 100.00%, 10 of 10 mutations caught',
            '6 classes, 44 of 45 mutations caught, minimum 80% for each class',
            'verdict: pass',
        ]);
});

it('fails when a class is below 80 over its mutations, also when the shards together reach it', function (): void {
    $plan = verdictPlan();
    $verdict = MutationVerdict::judge($plan, verdictReports($plan, ['Charlie' => [10, 7]]), JobResult::Success, JobResult::Success);

    expect($verdict->passed())->toBeFalse()
        ->and($verdict->failures)->toBe(['Cbox\Cms\Core\Planted\Domain\Charlie is below 80% over its mutations: 70.00%'])
        ->and($verdict->lines())->toContain('fail  Cbox\Cms\Core\Planted\Domain\Charlie: 70.00%, 7 of 10 mutations caught', '6 classes, 57 of 60 mutations caught, minimum 80% for each class', 'verdict: fail');
});

it('fails when the gates or the shard jobs did not succeed, whatever the reports say', function (JobResult $gates, JobResult $shards, string $failure): void {
    $plan = verdictPlan();
    $verdict = MutationVerdict::judge($plan, verdictReports($plan), $gates, $shards);

    expect($verdict->passed())->toBeFalse()
        ->and($verdict->failures)->toBe([$failure]);
})->with([
    'the gates failed' => [JobResult::Failure, JobResult::Success, 'the gates ended failure'],
    'the gates were cancelled' => [JobResult::Cancelled, JobResult::Success, 'the gates ended cancelled'],
    'a shard failed' => [JobResult::Success, JobResult::Failure, 'the mutation shards ended failure'],
    'the shards were skipped' => [JobResult::Success, JobResult::Skipped, 'the mutation shards ended skipped'],
]);

it('fails when a shard\'s check failed, even with every class at 80', function (): void {
    $plan = verdictPlan();
    $verdict = MutationVerdict::judge($plan, verdictReports($plan, failed: [2]), JobResult::Success, JobResult::Success);

    expect($verdict->failures)->toBe(['shard 2 of 3 failed']);
});

it('fails when a shard did not report, reported twice, reported another plan\'s shard or other sources, or left a class without a count', function (callable $reports, array $failures): void {
    $plan = verdictPlan();
    $made = $reports($plan);
    $verdict = MutationVerdict::judge($plan, is_array($made) ? array_values(array_filter($made, static fn (mixed $report): bool => $report instanceof MutationShardReport)) : [], JobResult::Success, JobResult::Success);

    expect($verdict->passed())->toBeFalse()
        ->and($verdict->failures)->toBe($failures);
})->with([
    'a missing report' => [static fn (MutationPlan $plan): array => array_slice(verdictReports($plan), 0, 2), ['shard 3 of 3 did not report']],
    'no report at all' => [static fn (MutationPlan $plan): array => [], ['shard 1 of 3 did not report', 'shard 2 of 3 did not report', 'shard 3 of 3 did not report']],
    'a report twice' => [static fn (MutationPlan $plan): array => [...verdictReports($plan), verdictReports($plan)[0]], ['shard 1 of 3 reported twice']],
    'a report of another plan' => [static fn (MutationPlan $plan): array => [...verdictReports($plan), new MutationShardReport(4, 4, [], true, [])], ['a report of shard 4 of 4, but the plan has 3 shards']],
    'other sources' => [static fn (MutationPlan $plan): array => [
        new MutationShardReport(1, 3, array_slice($plan->shard(1)->paths(), 0, 1), true, []),
        ...array_slice(verdictReports($plan), 1),
    ], ['shard 1 of 3 reported other sources than the plan gave it']],
    'a class without a count' => [static fn (MutationPlan $plan): array => [
        new MutationShardReport(1, 3, $plan->shard(1)->paths(), true, array_slice(verdictReports($plan)[0]->classes, 1)),
        ...array_slice(verdictReports($plan), 1),
    ], [sprintf('%s has no mutation count in shard 1 of 3', verdictPlan()->shard(1)->sources[0]->name)]],
]);

it('fails a plan without a base of the change, whose one shard fails with the reason', function (): void {
    $plan = MutationShards::plan(MutationScope::unresolved('CMS_CI_BASE_REF is empty'));
    $verdict = MutationVerdict::judge($plan, [new MutationShardReport(1, 1, [], false, [])], JobResult::Success, JobResult::Failure);

    expect($verdict->failures)->toBe([
        'the plan has no base of the change: CMS_CI_BASE_REF is empty',
        'the mutation shards ended failure',
        'shard 1 of 1 failed',
    ]);
});

it('passes a change without changed sources, whose one shard mutated nothing', function (): void {
    $plan = MutationShards::plan(MutationScope::changed('abc123', []));

    expect(MutationVerdict::judge($plan, [new MutationShardReport(1, 1, [], true, [])], JobResult::Success, JobResult::Success)->lines())
        ->toBe(['0 classes, 0 of 0 mutations caught, minimum 80% for each class', 'verdict: pass']);
});

it('round-trips a shard\'s report through its JSON, and refuses one that does not hold together', function (): void {
    $report = verdictReports(verdictPlan(), ['Alpha' => [7, 3]])[2];
    $json = MutationShardReportJson::encode($report);

    expect(MutationShardReportJson::decode($json))->toEqual($report)
        ->and(static fn (): MutationShardReport => MutationShardReportJson::decode('{"format": 1}'))->toThrow(InvalidArgumentException::class, 'not in format 2')
        ->and(static fn (): MutationShardReport => new MutationShardReport(1, 1, ['packages/a/src/A.php'], true, [new ClassTally('packages/a/src/B.php', 'B', new MutationCount(1, 1))]))
        ->toThrow(InvalidArgumentException::class, 'are not its sources')
        ->and(static fn (): MutationShardReport => new MutationShardReport(2, 1, [], true, []))->toThrow(InvalidArgumentException::class, 'Shard 2 of 1 is not a shard.');
});

it('reads its options: the plan, the reports, and how the gates and the shards ended, in GitHub\'s words', function (): void {
    $options = MutationVerdictOptions::parse(['--plan=p.json', '--reports=r', '--gates=success', '--shards=cancelled']);

    expect([$options->plan, $options->reports, $options->gates, $options->shards])->toBe(['p.json', 'r', JobResult::Success, JobResult::Cancelled])
        ->and(static fn (): MutationVerdictOptions => MutationVerdictOptions::parse(['--plan=p.json', '--reports=r', '--gates=success']))->toThrow(InvalidArgumentException::class, '--shards is missing')
        ->and(static fn (): MutationVerdictOptions => MutationVerdictOptions::parse(['--plan=p.json', '--reports=r', '--gates=green', '--shards=success']))->toThrow(InvalidArgumentException::class, '--gates=green is not a result')
        ->and(static fn (): MutationVerdictOptions => MutationVerdictOptions::parse(['--plan=a', '--plan=b']))->toThrow(InvalidArgumentException::class, 'repeated');
});

/**
 * Runs `composer mutation:verdict` as the verdict job does, on a directory of planted artifacts.
 *
 * @param  list<MutationShardReport>  $reports
 */
function runVerdictScript(MutationPlan $plan, array $reports, string $gates = 'success', string $shards = 'success', string $extraFile = ''): Process
{
    $artifacts = ScratchDirectory::make();
    ScratchDirectory::write($artifacts.'/mutation-plan/mutation-plan.json', MutationPlanJson::encode($plan));

    foreach ($reports as $report) {
        ScratchDirectory::write("{$artifacts}/mutation-shard-{$report->index}/".MutationShardReportJson::FILE_NAME, MutationShardReportJson::encode($report));
    }

    if ($extraFile !== '') {
        ScratchDirectory::write("{$artifacts}/mutation-shard-9/".MutationShardReportJson::FILE_NAME, $extraFile);
    }

    $process = new Process([PHP_BINARY, 'tools/bin/mutation-verdict.php', "--plan={$artifacts}/mutation-plan/mutation-plan.json", "--reports={$artifacts}", "--gates={$gates}", "--shards={$shards}"], Phpstan::root(), null, null, 60);
    $process->run();

    return $process;
}

it('exits 0 from composer mutation:verdict when every shard of the plan passed, and 1 when a class is below 80 in its shard', function (): void {
    $plan = verdictPlan();
    $passing = runVerdictScript($plan, verdictReports($plan));
    $below = runVerdictScript($plan, verdictReports($plan, ['Foxtrot' => [4, 3]]));

    expect($passing->getExitCode())->toBe(0, $passing->getErrorOutput().$passing->getOutput())
        ->and($passing->getOutput())->toContain('mutation:verdict: 3 shards of ', 'gates success, shard jobs success, 3 reports', "verdict: pass\n")
        ->and($below->getExitCode())->toBe(1)
        ->and($below->getOutput())->toContain('failed: Cbox\Cms\Core\Planted\Adapter\Foxtrot is below 80% over its mutations: 75.00%', "verdict: fail\n");
});

it('exits 1 from composer mutation:verdict when a gate failed, a shard is missing or a report cannot be read, and 2 on a usage error', function (): void {
    $plan = verdictPlan();
    $gates = runVerdictScript($plan, verdictReports($plan), gates: 'failure');
    $missing = runVerdictScript($plan, array_slice(verdictReports($plan), 1));
    $unreadable = runVerdictScript($plan, verdictReports($plan), extraFile: '{"format": 7}');
    $usage = new Process([PHP_BINARY, 'tools/bin/mutation-verdict.php', '--plan=x'], Phpstan::root());
    $usage->run();

    expect($gates->getExitCode())->toBe(1)
        ->and($gates->getOutput())->toContain('failed: the gates ended failure', 'verdict: fail')
        ->and($missing->getExitCode())->toBe(1)
        ->and($missing->getOutput())->toContain('failed: shard 1 of 3 did not report')
        ->and($unreadable->getExitCode())->toBe(1)
        ->and($unreadable->getOutput())->toContain('failed: unreadable shard report ', 'not in format 2', "verdict: fail\n")
        ->and($usage->getExitCode())->toBe(2);
});

it('writes the plan and GitHub\'s step outputs from composer mutation:plan, from this checkout\'s change', function (): void {
    $scratch = ScratchDirectory::make();
    $process = new Process([PHP_BINARY, 'tools/bin/mutation-plan.php', "--output={$scratch}/plan.json", "--github-output={$scratch}/outputs"], Phpstan::root(), ['CMS_CI_BASE_REF' => 'HEAD'], null, 60);
    $process->run();
    $plan = MutationPlanJson::decode((string) file_get_contents($scratch.'/plan.json'));

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toContain('mutation:plan: 0 changed files since ', 'in 1 shards of at most 10 files', 'shard 1 of 1: 0 files')
        ->and($plan->count())->toBe(1)
        ->and($plan->sources())->toBe([])
        ->and((string) file_get_contents($scratch.'/outputs'))->toBe("count=1\nshards=[1]\n");
});
