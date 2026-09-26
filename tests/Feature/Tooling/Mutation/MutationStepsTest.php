<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Mutation;

use Cbox\Cms\Contracts\Ids\PrincipalId;
use Cbox\Cms\Core\Doctor\Adapter\DoctorConnection;
use Cbox\Cms\Core\Partitions\Infrastructure\PartitionCatalog;
use Cbox\Cms\Core\ReceiptStore\Adapter\PostgresReceiptStore;
use Cbox\Cms\Tests\Support\Tooling\ScriptedProcessRunner;
use Cbox\Cms\Tooling\Check\Domain\CheckListener;
use Cbox\Cms\Tooling\Check\Domain\CheckRunner;
use Cbox\Cms\Tooling\Check\Domain\Gate;
use Cbox\Cms\Tooling\Check\Domain\GateResult;
use Cbox\Cms\Tooling\Check\Domain\ProcessOutcome;
use Cbox\Cms\Tooling\Check\Domain\Step;
use Cbox\Cms\Tooling\Check\Domain\StepResult;
use Cbox\Cms\Tooling\Check\Domain\StepStatus;
use Cbox\Cms\Tooling\Mutation\Domain\ChangedSource;
use Cbox\Cms\Tooling\Mutation\Domain\MutationReportReader;
use Cbox\Cms\Tooling\Mutation\Domain\MutationScope;
use Cbox\Cms\Tooling\Mutation\Domain\MutationSteps;
use InvalidArgumentException;

/*
 * The step builder of mutation on changed files (GUARDRAILS 9 and 10): which Pest runs the PR
 * profile's gate 5 makes of what changed since the base, with which flags, suites and variables.
 * GitMutationScopeTest finds the changes in git, and tests/Mutation runs the steps for real.
 */

final class MutationListener implements CheckListener
{
    public function gateStarted(Gate $gate): void {}

    public function stepFinished(Gate $gate, StepResult $result): void {}
}

function runMutationSteps(MutationScope $scope, ScriptedProcessRunner $runner): GateResult
{
    $report = new CheckRunner($runner, new MutationListener)->run([new Gate(5, 'Pest', MutationSteps::for($scope, '/usr/bin/php'))], '/srv/checkout');

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

it('mutates a changed Domain class against the fast suites in parallel, and a changed Adapter class with the Postgres suite serially, each with --min=80', function (): void {
    $adapter = new ChangedSource('packages/core/src/ReceiptStore/Adapter/PostgresReceiptStore.php', PostgresReceiptStore::class);
    $domain = new ChangedSource('packages/contracts/src/Ids/PrincipalId.php', PrincipalId::class);
    $steps = MutationSteps::for(MutationScope::changed('abc123', [$adapter, $domain]), '/usr/bin/php');

    expect(array_map(static fn (Step $step): string => $step->name, $steps))->toBe(['Mutation on changed files, fast suites', 'Mutation on changed files, with Postgres'])
        ->and($steps[0]->command)->toBe([
            '/usr/bin/php', 'vendor/bin/pest', '--testsuite=Unit,Codecs,Contract,Actions,Arch', '--fail-on-skipped', '--fail-on-incomplete',
            '--mutate', '--parallel', '--everything', '--path=packages/contracts/src/Ids/PrincipalId.php', '--min=80', '--ignore-min-score-on-zero-mutations',
        ])
        ->and($steps[1]->command)->toBe([
            '/usr/bin/php', 'vendor/bin/pest', '--testsuite=Unit,Codecs,Contract,Postgres,Actions,Arch', '--fail-on-skipped', '--fail-on-incomplete',
            '--mutate', '--everything', '--path=packages/core/src/ReceiptStore/Adapter/PostgresReceiptStore.php', '--min=80', '--ignore-min-score-on-zero-mutations',
        ])
        ->and($steps[1]->command)->not->toContain('--parallel')
        ->and($steps[0]->reader)->toEqual(new MutationReportReader([$domain], 80))
        ->and($steps[1]->reader)->toEqual(new MutationReportReader([$adapter], 80));

    foreach ($steps as $step) {
        expect($step->environment)->toBe(['PHP_INI_SCAN_DIR' => ':tools/mutation', 'CMS_MUTATION_REPORT' => '1'])
            ->and($step->ownProcessGroup)->toBeFalse()
            ->and(array_filter($step->command, static fn (string $argument): bool => str_starts_with($argument, '--class')))->toBe([]);
    }
});

it('names every changed file of a group in one --path, sorted, and makes only the steps that have files', function (): void {
    $scope = MutationScope::changed('abc123', [
        new ChangedSource('packages/core/src/Partitions/Infrastructure/PartitionCatalog.php', PartitionCatalog::class),
        new ChangedSource('packages/core/src/Doctor/Adapter/DoctorConnection.php', DoctorConnection::class),
    ]);
    $steps = MutationSteps::for($scope, '/usr/bin/php');

    expect($steps)->toHaveCount(1)
        ->and($steps[0]->name)->toBe(MutationSteps::POSTGRES_NAME)
        ->and($steps[0]->command)->toContain('--path=packages/core/src/Doctor/Adapter/DoctorConnection.php,packages/core/src/Partitions/Infrastructure/PartitionCatalog.php');
});

it('passes a step whose report scores the changed classes at 80 or more, and fails one below, naming the class', function (): void {
    $scope = MutationScope::changed('abc123', [
        new ChangedSource('packages/contracts/src/Ids/PrincipalId.php', PrincipalId::class),
        new ChangedSource('packages/core/src/Doctor/Adapter/DoctorConnection.php', DoctorConnection::class),
    ]);
    $report = static fn (string $path, int $mutations, int $caught): string => "tests ...\n".MutationReportReader::MARKER
        .json_encode(['files' => [['caught' => $caught, 'mutations' => $mutations, 'path' => $path]], 'format' => 1], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    $runner = new ScriptedProcessRunner(static fn (array $command): ProcessOutcome => in_array('--parallel', $command, true)
        ? new ProcessOutcome(0, $report('packages/contracts/src/Ids/PrincipalId.php', 10, 9), 1.0)
        : new ProcessOutcome(1, $report('packages/core/src/Doctor/Adapter/DoctorConnection.php', 10, 6), 1.0));

    $gate = runMutationSteps($scope, $runner);

    expect($gate->step(MutationSteps::FAST_NAME)?->status)->toBe(StepStatus::Pass)
        ->and($gate->step(MutationSteps::FAST_NAME)?->notes)->toBe([
            'Cbox\Cms\Contracts\Ids\PrincipalId: 90.00%, 9 of 10 mutations caught',
            'score 90.00% of 10 mutations, minimum 80%',
        ])
        ->and($gate->step(MutationSteps::POSTGRES_NAME)?->status)->toBe(StepStatus::Fail)
        ->and($gate->step(MutationSteps::POSTGRES_NAME)?->reason)->toBe('mutation score 60.00% is below 80%; below it: Cbox\Cms\Core\Doctor\Adapter\DoctorConnection 60.00%');
});
