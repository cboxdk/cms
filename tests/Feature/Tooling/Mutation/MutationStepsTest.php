<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Mutation;

use Cbox\Cms\Contracts\Ids\PrincipalId;
use Cbox\Cms\Core\Doctor\Adapter\DoctorConnection;
use Cbox\Cms\Core\Partitions\Infrastructure\PartitionCatalog;
use Cbox\Cms\Core\ReceiptStore\Adapter\PostgresReceiptStore;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Cbox\Cms\Tests\Support\Tooling\ScriptedProcessRunner;
use Cbox\Cms\Tooling\Check\Domain\CheckRunner;
use Cbox\Cms\Tooling\Check\Domain\Gate;
use Cbox\Cms\Tooling\Check\Domain\GateResult;
use Cbox\Cms\Tooling\Check\Domain\ProcessOutcome;
use Cbox\Cms\Tooling\Check\Domain\Step;
use Cbox\Cms\Tooling\Check\Domain\StepStatus;
use Cbox\Cms\Tooling\Mutation\Domain\CaughtByFastSuites;
use Cbox\Cms\Tooling\Mutation\Domain\ChangedSource;
use Cbox\Cms\Tooling\Mutation\Domain\ClassTally;
use Cbox\Cms\Tooling\Mutation\Domain\EquivalentMutations;
use Cbox\Cms\Tooling\Mutation\Domain\MutationLedger;
use Cbox\Cms\Tooling\Mutation\Domain\MutationReportReader;
use Cbox\Cms\Tooling\Mutation\Domain\MutationScope;
use Cbox\Cms\Tooling\Mutation\Domain\MutationSteps;
use Cbox\Cms\Tooling\Mutation\Domain\MutationTally;
use InvalidArgumentException;
use Pest\Mutate\Mutators\Logical\TrueToFalse;

/*
 * The step builder of mutation on changed files (GUARDRAILS 9 and 10): which Pest runs the PR
 * profile's gate 5 makes of what changed since the base, with which flags, suites and variables.
 * GitMutationScopeTest finds the changes in git, and tests/Mutation runs the steps for real.
 */

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

/**
 * Runs the steps in a scratch checkout, where the step with Postgres writes the mutations the fast
 * suites caught when it runs.
 */
function runMutationSteps(MutationScope $scope, ScriptedProcessRunner $runner): GateResult
{
    $report = new CheckRunner($runner, new MutationListener)->run([new Gate(5, 'Pest', MutationSteps::for($scope, '/usr/bin/php'))], ScratchDirectory::make());

    return $report->gate(5) ?? throw new InvalidArgumentException('No gate 5.');
}

it('passes with 0 changed classes when no source below packages/*/src changed, and runs nothing', function (): void {
    $runner = ScriptedProcessRunner::passing();
    $steps = MutationSteps::for(MutationScope::changed('abc123, the merge base of CMS_CI_BASE_REF=main and HEAD', []), '/usr/bin/php');
    $gate = runMutationSteps(MutationScope::changed('abc123, the merge base of CMS_CI_BASE_REF=main and HEAD', []), $runner);

    expect($steps)->toHaveCount(1)
        ->and($steps[0]->name)->toBe('Mutation on changed files')
        ->and($steps[0]->runs())->toBeFalse()
        ->and($steps[0]->decided)->toBe(StepStatus::Pass)
        ->and($runner->calls)->toBe([])
        ->and($gate->status())->toBe(StepStatus::Pass)
        ->and($gate->step('Mutation on changed files')?->notes)->toBe(['0 changed classes since abc123, the merge base of CMS_CI_BASE_REF=main and HEAD']);
});

it('fails with the reason when the base of the change is missing, and runs nothing', function (): void {
    $runner = ScriptedProcessRunner::passing();
    $gate = runMutationSteps(MutationScope::unresolved('CMS_CI_BASE_REF=nosuch names no commit in /srv/checkout: fatal: bad revision'), $runner);
    $step = $gate->step('Mutation on changed files');

    expect($runner->calls)->toBe([])
        ->and($gate->status())->toBe(StepStatus::Fail)
        ->and($step?->status)->toBe(StepStatus::Fail)
        ->and($step?->reason)->toBe('CMS_CI_BASE_REF=nosuch names no commit in /srv/checkout: fatal: bad revision')
        ->and($step?->notes)->toBe([]);
});

it('mutates every changed class against the fast suites in parallel, and again against the Postgres suite alone, in parallel, which judges each class at 80 over both runs', function (): void {
    $adapter = new ChangedSource('packages/core/src/ReceiptStore/Adapter/PostgresReceiptStore.php', PostgresReceiptStore::class);
    $domain = new ChangedSource('packages/contracts/src/Ids/PrincipalId.php', PrincipalId::class);
    $tally = new MutationTally;
    $steps = MutationSteps::for(MutationScope::changed('abc123', [$adapter, $domain]), '/usr/bin/php', $tally);
    $ledger = new MutationLedger;
    $paths = '--path=packages/contracts/src/Ids/PrincipalId.php,packages/core/src/ReceiptStore/Adapter/PostgresReceiptStore.php';

    expect(array_map(static fn (Step $step): string => $step->name, $steps))->toBe(['Mutation on changed files, fast suites', 'Mutation on changed files, with Postgres'])
        ->and($steps[0]->command)->toBe([
            '/usr/bin/php', 'vendor/bin/pest', '--testsuite=Unit,Codecs,Contract,Actions,Arch', '--fail-on-skipped', '--fail-on-incomplete',
            '--mutate', '--parallel', '--everything', $paths,
        ])
        ->and($steps[1]->command)->toBe([
            '/usr/bin/php', 'vendor/bin/pest', '--testsuite=Postgres', '--fail-on-skipped', '--fail-on-incomplete',
            '--mutate', '--parallel', '--everything', $paths,
        ])
        ->and($steps[0]->reader)->toEqual(new MutationReportReader([], 80, records: $ledger))
        ->and($steps[1]->reader)->toEqual(new MutationReportReader([$domain, $adapter], 80, counts: $ledger, tally: $tally, equivalents: EquivalentMutations::kernel()))
        ->and($steps[0]->precheck)->toBeNull()
        ->and($steps[1]->precheck)->toEqual(new CaughtByFastSuites([$domain, $adapter], $ledger, $tally, EquivalentMutations::kernel()));

    $fast = $steps[0]->reader instanceof MutationReportReader ? $steps[0]->reader->records : null;
    $postgres = $steps[1]->reader instanceof MutationReportReader ? $steps[1]->reader->counts : null;

    expect($fast)->toBeInstanceOf(MutationLedger::class)
        ->and($postgres)->toBe($fast)
        ->and($steps[1]->precheck instanceof CaughtByFastSuites ? $steps[1]->precheck->ledger : null)->toBe($fast)
        ->and($steps[1]->reader instanceof MutationReportReader ? $steps[1]->reader->tally : null)->toBe($tally);

    foreach ($steps as $step) {
        expect($step->environment)->toBe(['PHP_INI_SCAN_DIR' => ':tools/mutation', 'CMS_MUTATION_REPORT' => '1'])
            ->and($step->ownProcessGroup)->toBeFalse()
            ->and(array_filter($step->command, static fn (string $argument): bool => str_starts_with($argument, '--class') || str_starts_with($argument, '--min')))->toBe([]);
    }
});

it('never runs the Postgres suite in one PHP process with the other suites, whose tests and coverage together outgrow the memory limit', function (): void {
    // Regression (M0-R1-10): the step with Postgres ran Unit, Codecs, Contract, Postgres, Actions
    // and Arch serially in one process with coverage, and PHP ran out of its 512M in the Arch suite.
    $scope = MutationScope::changed('abc123', [
        new ChangedSource('packages/core/src/Doctor/Adapter/DoctorConnection.php', DoctorConnection::class),
        new ChangedSource('packages/core/src/Partitions/Infrastructure/PartitionCatalog.php', PartitionCatalog::class),
        new ChangedSource('packages/contracts/src/Ids/PrincipalId.php', PrincipalId::class),
    ]);
    $suites = array_map(
        static fn (Step $step): array => explode(',', substr((string) current(array_filter($step->command, static fn (string $argument): bool => str_starts_with($argument, '--testsuite='))), strlen('--testsuite='))),
        MutationSteps::for($scope, '/usr/bin/php'),
    );

    expect(array_values(array_filter($suites, static fn (array $step): bool => in_array('Postgres', $step, true))))->toBe([['Postgres']]);
});

it('runs the step with Postgres with --parallel against the Postgres suite alone, so that each worker runs in a test database of its own', function (): void {
    $steps = MutationSteps::for(MutationScope::changed('abc123', [
        new ChangedSource('packages/core/src/Partitions/Infrastructure/PartitionCatalog.php', PartitionCatalog::class),
    ]), '/usr/bin/php');
    $postgres = $steps[1] ?? null;

    expect($postgres?->name)->toBe(MutationSteps::POSTGRES_NAME)
        ->and($postgres?->command)->toContain('--parallel')
        ->and(array_values(array_filter($postgres->command ?? [], static fn (string $argument): bool => str_starts_with($argument, '--testsuite='))))->toBe(['--testsuite=Postgres']);
});

it('names every changed file in one --path, sorted, and mutates a class of any layer against the Postgres suite too', function (): void {
    // Regression (M1-T66): only Adapter and Infrastructure were mutated against the Postgres
    // suite, so a class whose tests are all there, such as an Artisan command, scored 0.
    $scope = MutationScope::changed('abc123', [
        new ChangedSource('packages/core/src/Partitions/Infrastructure/PartitionCatalog.php', PartitionCatalog::class),
        new ChangedSource('packages/core/src/Doctor/Adapter/DoctorConnection.php', DoctorConnection::class),
    ]);
    $steps = MutationSteps::for($scope, '/usr/bin/php');
    $domainOnly = MutationSteps::for(MutationScope::changed('abc123', [new ChangedSource('packages/contracts/src/Ids/PrincipalId.php', PrincipalId::class)]), '/usr/bin/php');

    expect(array_map(static fn (Step $step): string => $step->name, $steps))->toBe([MutationSteps::FAST_NAME, MutationSteps::POSTGRES_NAME])
        ->and($steps[0]->command)->toContain('--path=packages/core/src/Doctor/Adapter/DoctorConnection.php,packages/core/src/Partitions/Infrastructure/PartitionCatalog.php')
        ->and($steps[1]->command)->toContain('--path=packages/core/src/Doctor/Adapter/DoctorConnection.php,packages/core/src/Partitions/Infrastructure/PartitionCatalog.php')
        ->and($steps[0]->reader)->toEqual(new MutationReportReader([], 80, records: new MutationLedger))
        ->and(array_map(static fn (Step $step): string => $step->name, $domainOnly))->toBe([MutationSteps::FAST_NAME, MutationSteps::POSTGRES_NAME])
        ->and($domainOnly[1]->command)->toContain('--testsuite=Postgres', '--path=packages/contracts/src/Ids/PrincipalId.php');
});

/**
 * A report of one file with the given mutations, by id, caught or not.
 *
 * @param  array<string, bool>  $mutations
 */
function mutationStepReport(string $path, array $mutations): string
{
    return "tests ...\n".MutationReportReader::MARKER.json_encode([
        'files' => [['mutations' => array_map(static fn (string $id, bool $caught): array => ['caught' => $caught, 'id' => $id, 'line' => 1, 'mutator' => TrueToFalse::class], array_keys($mutations), $mutations), 'path' => $path]],
        'format' => 3,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
}

/**
 * The Postgres suite's run (--testsuite=Postgres) prints its report, the fast suites' run the other.
 */
function scriptedMutationRuns(string $fast, string $postgres): ScriptedProcessRunner
{
    return new ScriptedProcessRunner(static fn (array $command): ProcessOutcome => in_array('--testsuite=Postgres', $command, true)
        ? new ProcessOutcome(0, $postgres, 1.0)
        : new ProcessOutcome(0, $fast, 1.0));
}

it('passes a step whose report scores the changed classes at 80 or more, and fails one below, naming the class', function (): void {
    $scope = MutationScope::changed('abc123', [
        new ChangedSource('packages/contracts/src/Ids/PrincipalId.php', PrincipalId::class),
        new ChangedSource('packages/core/src/Doctor/Adapter/DoctorConnection.php', DoctorConnection::class),
    ]);
    $fast = "tests ...\n".MutationReportReader::MARKER.json_encode(['files' => [
        ['mutations' => array_map(static fn (int $n): array => ['caught' => $n <= 9, 'id' => 'p'.$n, 'line' => 1, 'mutator' => TrueToFalse::class], range(1, 10)), 'path' => 'packages/contracts/src/Ids/PrincipalId.php'],
        ['mutations' => array_map(static fn (int $n): array => ['caught' => $n <= 2, 'id' => 'd'.$n, 'line' => 1, 'mutator' => TrueToFalse::class], range(1, 10)), 'path' => 'packages/core/src/Doctor/Adapter/DoctorConnection.php'],
    ], 'format' => 3], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    $postgres = mutationStepReport('packages/core/src/Doctor/Adapter/DoctorConnection.php', array_combine(
        array_map(static fn (int $n): string => 'd'.$n, range(1, 10)),
        array_map(static fn (int $n): bool => $n >= 7, range(1, 10)),
    ));

    $gate = runMutationSteps($scope, scriptedMutationRuns($fast, $postgres));

    expect($gate->step(MutationSteps::FAST_NAME)?->status)->toBe(StepStatus::Pass)
        ->and($gate->step(MutationSteps::FAST_NAME)?->notes)->toBe(['recorded for the step with Postgres, which judges every changed source over both runs'])
        ->and($gate->step(MutationSteps::POSTGRES_NAME)?->status)->toBe(StepStatus::Fail)
        ->and($gate->step(MutationSteps::POSTGRES_NAME)?->notes)->toContain(
            'Cbox\Cms\Contracts\Ids\PrincipalId: 90.00%, 9 of 10 mutations caught',
            'Cbox\Cms\Core\Doctor\Adapter\DoctorConnection: 60.00%, 6 of 10 mutations caught',
            'counted with the fast suites\' run, which caught 11 of them',
        )
        ->and($gate->step(MutationSteps::POSTGRES_NAME)?->reason)->toBe('below 80% over its mutations: Cbox\Cms\Core\Doctor\Adapter\DoctorConnection 60.00%');
});

it('counts in the step with Postgres what the fast suites caught of an Adapter class, as one run of every suite would', function (): void {
    $adapter = new ChangedSource('packages/core/src/Doctor/Adapter/DoctorConnection.php', DoctorConnection::class);
    $runner = scriptedMutationRuns(
        mutationStepReport($adapter->path, ['a' => true, 'b' => true, 'c' => false, 'd' => false, 'e' => false]),
        mutationStepReport($adapter->path, ['a' => false, 'b' => false, 'c' => true, 'd' => true, 'e' => false]),
    );

    $gate = runMutationSteps(MutationScope::changed('abc123', [$adapter]), $runner);

    expect(count($runner->calls))->toBe(2)
        ->and($gate->step(MutationSteps::FAST_NAME)?->status)->toBe(StepStatus::Pass)
        ->and($gate->step(MutationSteps::FAST_NAME)?->notes)->toBe(['recorded for the step with Postgres, which judges every changed source over both runs'])
        ->and($gate->step(MutationSteps::POSTGRES_NAME)?->status)->toBe(StepStatus::Pass)
        ->and($gate->step(MutationSteps::POSTGRES_NAME)?->notes)->toBe([
            'Cbox\Cms\Core\Doctor\Adapter\DoctorConnection: 80.00%, 4 of 5 mutations caught',
            'counted with the fast suites\' run, which caught 2 of them',
            'score 80.00% of 5 mutations, minimum 80% for each class',
        ]);
});

it('passes the step with Postgres without running the Postgres suite when the fast suites caught every mutation of its classes', function (): void {
    $adapter = new ChangedSource('packages/core/src/Doctor/Adapter/DoctorConnection.php', DoctorConnection::class);
    $infrastructure = new ChangedSource('packages/core/src/Partitions/Infrastructure/PartitionCatalog.php', PartitionCatalog::class);
    $runner = scriptedMutationRuns(mutationStepReport($adapter->path, ['a' => true, 'b' => true, 'c' => true]), 'never run');

    $gate = runMutationSteps(MutationScope::changed('abc123', [$adapter, $infrastructure]), $runner);

    expect($runner->calls)->toHaveCount(1)
        ->and($runner->calls[0]->command)->toContain('--testsuite=Unit,Codecs,Contract,Actions,Arch')
        ->and($gate->step(MutationSteps::POSTGRES_NAME)?->status)->toBe(StepStatus::Pass)
        ->and($gate->step(MutationSteps::POSTGRES_NAME)?->exitCode)->toBeNull()
        ->and($gate->step(MutationSteps::POSTGRES_NAME)?->notes)->toBe(['the fast suites caught all 3 mutations of the changed sources, so the Postgres suite cannot change the score and is not run']);
});

it('runs the Postgres suite when the fast suites left a mutation of its classes, or printed no report', function (string $fast): void {
    $adapter = new ChangedSource('packages/core/src/Doctor/Adapter/DoctorConnection.php', DoctorConnection::class);
    $runner = scriptedMutationRuns($fast, mutationStepReport($adapter->path, ['a' => true, 'b' => true]));

    $gate = runMutationSteps(MutationScope::changed('abc123', [$adapter]), $runner);

    expect($runner->calls)->toHaveCount(2)
        ->and($runner->calls[1]->command)->toContain('--testsuite=Postgres')
        ->and($gate->step(MutationSteps::POSTGRES_NAME)?->status)->toBe(StepStatus::Pass)
        ->and($gate->step(MutationSteps::POSTGRES_NAME)?->exitCode)->toBe(0);
})->with([
    'one left' => [mutationStepReport('packages/core/src/Doctor/Adapter/DoctorConnection.php', ['a' => true, 'b' => false])],
    'no report' => ["  FAILED  Tests\\Unit\\ATest\n"],
]);

it('refuses the precheck of the step with Postgres without sources', function (): void {
    expect(static fn (): CaughtByFastSuites => new CaughtByFastSuites([], new MutationLedger))->toThrow(InvalidArgumentException::class);
});

it('passes the step with Postgres without running it when the fast suites\' report shows no mutations of its classes', function (): void {
    $precheck = new CaughtByFastSuites([new ChangedSource('packages/core/src/Doctor/Adapter/DoctorConnection.php', DoctorConnection::class)], $ledger = new MutationLedger);
    $before = $precheck->passedWithout();
    $ledger->record([]);

    expect($before)->toBeNull()
        ->and($precheck->passedWithout())->toBe('no mutations in the changed sources, as the fast suites\' run showed, so the Postgres suite is not run');
});

/**
 * Runs the steps with a tally, as a shard job does, and gives each counted class as path,
 * mutations and caught.
 *
 * @return list<array{string, int, int}>
 */
function tallyOfMutationSteps(MutationScope $scope, ScriptedProcessRunner $runner): array
{
    $tally = new MutationTally;
    new CheckRunner($runner, new MutationListener)->run([new Gate(5, 'Pest', MutationSteps::for($scope, '/usr/bin/php', $tally))], ScratchDirectory::make());

    return array_map(static fn (ClassTally $class): array => [$class->path, $class->count->mutations, $class->count->caught], $tally->classes());
}

it('counts each changed class for the shard\'s report: the fast suites\' classes from their run, the Adapter classes over both runs', function (): void {
    $domain = new ChangedSource('packages/contracts/src/Ids/PrincipalId.php', PrincipalId::class);
    $adapter = new ChangedSource('packages/core/src/Doctor/Adapter/DoctorConnection.php', DoctorConnection::class);
    $fast = "tests ...\n".MutationReportReader::MARKER.json_encode(['files' => [
        ['mutations' => [['caught' => true, 'id' => 'p1', 'line' => 1, 'mutator' => TrueToFalse::class], ['caught' => false, 'id' => 'p2', 'line' => 1, 'mutator' => TrueToFalse::class]], 'path' => $domain->path],
        ['mutations' => [['caught' => true, 'id' => 'd1', 'line' => 1, 'mutator' => TrueToFalse::class], ['caught' => false, 'id' => 'd2', 'line' => 1, 'mutator' => TrueToFalse::class], ['caught' => false, 'id' => 'd3', 'line' => 1, 'mutator' => TrueToFalse::class]], 'path' => $adapter->path],
    ], 'format' => 3], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

    expect(tallyOfMutationSteps(MutationScope::changed('abc123', [$domain, $adapter]), scriptedMutationRuns($fast, mutationStepReport($adapter->path, ['d2' => true, 'd3' => false]))))->toBe([
        ['packages/contracts/src/Ids/PrincipalId.php', 2, 1],
        ['packages/core/src/Doctor/Adapter/DoctorConnection.php', 3, 2],
    ]);
});

it('counts the Adapter classes with the fast suites\' run when they caught every mutation and the step with Postgres did not run', function (): void {
    $adapter = new ChangedSource('packages/core/src/Doctor/Adapter/DoctorConnection.php', DoctorConnection::class);
    $infrastructure = new ChangedSource('packages/core/src/Partitions/Infrastructure/PartitionCatalog.php', PartitionCatalog::class);

    expect(tallyOfMutationSteps(MutationScope::changed('abc123', [$adapter, $infrastructure]), scriptedMutationRuns(mutationStepReport($adapter->path, ['a' => true, 'b' => true]), 'never run')))->toBe([
        ['packages/core/src/Doctor/Adapter/DoctorConnection.php', 2, 2],
        ['packages/core/src/Partitions/Infrastructure/PartitionCatalog.php', 0, 0],
    ]);
});

it('counts no class whose run printed no report, so the verdict fails it', function (): void {
    $domain = new ChangedSource('packages/contracts/src/Ids/PrincipalId.php', PrincipalId::class);

    expect(tallyOfMutationSteps(MutationScope::changed('abc123', [$domain]), scriptedMutationRuns("  FAILED  Tests\\Unit\\ATest\n", 'never run')))->toBe([]);
});
