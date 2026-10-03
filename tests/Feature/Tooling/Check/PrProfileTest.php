<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Check;

use Cbox\Cms\Contracts\Ids\PrincipalId;
use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\RecordedCommand;
use Cbox\Cms\Tests\Support\Tooling\ScriptedProcessRunner;
use Cbox\Cms\Tooling\Check\Boundary\CheckReportJson;
use Cbox\Cms\Tooling\Check\Domain\CheckReport;
use Cbox\Cms\Tooling\Check\Domain\CheckRunner;
use Cbox\Cms\Tooling\Check\Domain\ComposerAuditReader;
use Cbox\Cms\Tooling\Check\Domain\Gate;
use Cbox\Cms\Tooling\Check\Domain\LocalProfile;
use Cbox\Cms\Tooling\Check\Domain\ProcessOutcome;
use Cbox\Cms\Tooling\Check\Domain\Profile;
use Cbox\Cms\Tooling\Check\Domain\PrPart;
use Cbox\Cms\Tooling\Check\Domain\PrProfile;
use Cbox\Cms\Tooling\Check\Domain\ReportFormatter;
use Cbox\Cms\Tooling\Check\Domain\Step;
use Cbox\Cms\Tooling\Check\Domain\StepResult;
use Cbox\Cms\Tooling\Check\Domain\StepStatus;
use Cbox\Cms\Tooling\Mutation\Domain\ChangedSource;
use Cbox\Cms\Tooling\Mutation\Domain\MutationReportReader;
use Cbox\Cms\Tooling\Mutation\Domain\MutationScope;
use Cbox\Cms\Tooling\Mutation\Domain\MutationSteps;
use Cbox\Cms\Tooling\Mutation\Domain\MutationTally;
use InvalidArgumentException;

/*
 * The PR profile as CI runs it through bin/ci (`composer check -- --pr`): the steps of gates 1 to
 * 6 are the local profile's, so CI and a developer run the same commands. Mutation testing is
 * deferred until after v1 (Sylvester, 2 October 2026): by default gate 5 reports the Mutation
 * suite and mutation on changed files as not run with that reason, and with --mutation
 * (PrPart::all() and its parts) gate 5 adds both, as before the decision; gate 8 runs the Browser suite in a process group
 * of its own, gate 9 runs composer audit and npm audit and gate 10 runs composer docs:check;
 * everything else of the PR profile in GUARDRAILS 10 is reported as not run with a reason. The runs here use the scripted process
 * runner; BrowserStepTest runs a Browser step for real, tests/Mutation a mutation step.
 */

const PR_COMPOSER = ['/usr/bin/php', '/usr/bin/composer'];

/**
 * @return list<Gate>
 */
function prGates(?MutationScope $mutation = null, ?PrPart $part = null): array
{
    return PrProfile::gates('/usr/bin/php', PR_COMPOSER, $mutation ?? MutationScope::changed('abc123', []), $part);
}

/**
 * The JSON report of `composer audit --format=json`, as Composer prints it.
 *
 * @param  array<string, list<array<string, mixed>>>  $advisories
 * @param  array<string, ?string>  $abandoned
 */
function composerAuditJson(array $advisories = [], array $abandoned = []): string
{
    return json_encode(['advisories' => $advisories, 'abandoned' => $abandoned, 'filter' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
}

/**
 * Runs the PR profile with every command passing, except composer audit, which prints the given
 * output and exits with the given code.
 */
function runPrGates(string $composerAuditOutput, int $composerAuditExit): CheckReport
{
    $runner = new ScriptedProcessRunner(static fn (array $command): ProcessOutcome => in_array('audit', $command, true) && $command[0] !== 'npm'
        ? new ProcessOutcome($composerAuditExit, $composerAuditOutput, 0.1)
        : new ProcessOutcome(0, 'ok', 0.1));

    return new CheckRunner($runner, new SilentListener)->run(prGates(), '/srv/checkout');
}

/**
 * The numbers of the gates with a step reported as not run.
 *
 * @param  list<Gate>  $gates
 * @return list<int>
 */
function gatesWithNotRunSteps(array $gates): array
{
    return array_map(static fn (Gate $gate): int => $gate->number, array_values(array_filter($gates, static fn (Gate $gate): bool => array_any($gate->steps, static fn (Step $step): bool => $step->notRunReason !== null))));
}

/**
 * @param  list<Step>  $steps
 * @return list<array{string, list<string>, ?string}>
 */
function stepTriples(array $steps): array
{
    return array_map(static fn (Step $step): array => [$step->name, $step->command, $step->notRunReason], $steps);
}

it('runs the local profile\'s steps for gates 1 to 6, unchanged, and with --mutation adds only the Mutation suite and mutation on changed files to gate 5', function (): void {
    $local = LocalProfile::gates('/usr/bin/php', PR_COMPOSER);
    $scope = MutationScope::changed('abc123', [new ChangedSource('packages/contracts/src/Ids/PrincipalId.php', PrincipalId::class)]);
    $pr = prGates($scope, PrPart::all());

    expect(array_map(static fn (Gate $gate): int => $gate->number, $pr))->toBe(range(1, 11));

    foreach ([1, 2, 3, 4, 6] as $number) {
        expect($pr[$number - 1])->toEqual($local[$number - 1]);
    }

    $gate5 = $pr[4];
    $count = count($local[4]->steps);

    expect($gate5->title)->toBe($local[4]->title)
        ->and(stepTriples(array_slice($gate5->steps, 0, $count)))->toBe(stepTriples($local[4]->steps))
        ->and(stepTriples(array_slice($gate5->steps, $count, 1)))->toBe([
            ['Mutation', ['/usr/bin/php', 'vendor/bin/pest', '--testsuite=Mutation', '--fail-on-skipped', '--fail-on-incomplete'], null],
        ])
        ->and(array_slice($gate5->steps, $count + 1))->toEqual(MutationSteps::for($scope, '/usr/bin/php'))
        ->and(LocalProfile::OTHER_SUITES)->toContain(PrProfile::MUTATION_SUITE);
});

it('runs the local profile\'s steps for gates 1 to 6 by default, and reports the Mutation suite and mutation on changed files as deferred until after v1, without a scope', function (): void {
    $local = LocalProfile::gates('/usr/bin/php', PR_COMPOSER);
    $default = PrProfile::gates('/usr/bin/php', PR_COMPOSER);
    $optIn = prGates(MutationScope::changed('abc123', [new ChangedSource('packages/contracts/src/Ids/PrincipalId.php', PrincipalId::class)]), PrPart::all());

    expect(array_map(static fn (Gate $gate): int => $gate->number, $default))->toBe(range(1, 11))
        ->and($default)->toEqual(prGates(part: PrPart::withoutMutation()))
        ->and($default)->toEqual(prGates(MutationScope::unresolved('CMS_CI_BASE_REF is not set')));

    foreach ([1, 2, 3, 4, 6, 7, 8, 9, 10, 11] as $number) {
        expect($default[$number - 1])->toEqual($optIn[$number - 1]);
    }

    foreach ([1, 2, 3, 4, 6] as $number) {
        expect($default[$number - 1])->toEqual($local[$number - 1]);
    }

    expect(stepTriples($default[4]->steps))->toBe([
        ...stepTriples($local[4]->steps),
        ['Mutation', [], PrProfile::MUTATION_DEFERRED],
        [MutationSteps::NAME, [], PrProfile::MUTATION_DEFERRED],
    ])
        ->and(PrProfile::MUTATION_DEFERRED)->toBe('mutation testing deferred until after v1 (Sylvester, 2 October 2026); run it with --mutation, or CMS_CI_MUTATION=1 for bin/ci');
});

it('passes gate 5 by default when its suites pass, with the steps of mutation testing not run, and runs no mutation', function (): void {
    $runner = new ScriptedProcessRunner(static fn (array $command): ProcessOutcome => new ProcessOutcome(0, composerAuditJson(), 0.1));
    $report = new CheckRunner($runner, new SilentListener)->run(PrProfile::gates('/usr/bin/php', PR_COMPOSER), '/srv/checkout');

    expect($report->passed())->toBeTrue()
        ->and($report->gate(5)?->status())->toBe(StepStatus::Pass)
        ->and($report->gate(5)?->step('Mutation')?->status)->toBe(StepStatus::NotRun)
        ->and($report->gate(5)?->step(MutationSteps::NAME)?->status)->toBe(StepStatus::NotRun)
        ->and($report->gate(5)?->step(MutationSteps::NAME)?->reason)->toBe(PrProfile::MUTATION_DEFERRED)
        ->and(array_filter($runner->calls, static fn (RecordedCommand $call): bool => in_array('--mutate', $call->command, true) || in_array('--testsuite=Mutation', $call->command, true)))->toBe([])
        ->and(ReportFormatter::summary($report))->toContain('not run   Mutation on changed files: '.PrProfile::MUTATION_DEFERRED);
});

it('needs the scope of mutation on changed files only in a part that runs it', function (): void {
    expect(static fn (): array => PrProfile::gates('/usr/bin/php', PR_COMPOSER, null, PrPart::all()))->toThrow(InvalidArgumentException::class, 'Mutation on changed files needs its scope')
        ->and(static fn (): array => PrProfile::gates('/usr/bin/php', PR_COMPOSER, null, PrPart::shard(1, 2)))->toThrow(InvalidArgumentException::class)
        ->and(PrProfile::gates('/usr/bin/php', PR_COMPOSER, null, PrPart::gates()))->toHaveCount(11)
        ->and(PrProfile::gates('/usr/bin/php', PR_COMPOSER, null, PrPart::withoutMutation()))->toHaveCount(11);
});

it('runs mutation on changed files in gate 5 with --mutation and never reports it as not run: a missing base fails it, no change passes it', function (): void {
    $unresolved = prGates(MutationScope::unresolved('CMS_CI_BASE_REF is not set'), PrPart::all())[4];
    $unchanged = prGates(MutationScope::changed('abc123', []), PrPart::all())[4];

    expect(array_filter([...$unresolved->steps, ...$unchanged->steps], static fn (Step $step): bool => $step->notRunReason !== null))->toBe([])
        ->and(array_last($unresolved->steps)?->decided)->toBe(StepStatus::Fail)
        ->and(array_last($unresolved->steps)?->decision)->toBe('CMS_CI_BASE_REF is not set')
        ->and(array_last($unchanged->steps)?->decided)->toBe(StepStatus::Pass)
        ->and(array_last($unchanged->steps)?->decision)->toBe('0 changed classes since abc123');
});

it('reports gates 7 and 11 as not run, each with its own reason and never the local profile\'s', function (): void {
    expect(array_keys(PrProfile::NOT_RUN))->toBe([7, 11]);

    foreach (prGates() as $gate) {
        if (! isset(PrProfile::NOT_RUN[$gate->number])) {
            continue;
        }

        expect($gate->steps)->toHaveCount(1)
            ->and($gate->steps[0]->runs())->toBeFalse()
            ->and($gate->steps[0]->notRunReason)->toBe(PrProfile::NOT_RUN[$gate->number])
            ->and($gate->steps[0]->notRunReason)->not->toBe(LocalProfile::OUTSIDE_PROFILE);
    }

    expect(PrProfile::NOT_RUN[11])->toBe('not a command: review by someone other than the author needs branch protection on main that requires it, a repository setting on github.com/cboxdk/cms that Sylvester makes');

    // By default gate 5 has the steps of mutation testing not run as well (Sylvester, 2 October 2026).
    expect(array_unique(PrProfile::NOT_RUN))->toHaveCount(2)
        ->and(gatesWithNotRunSteps(prGates(part: PrPart::all())))->toBe([7, 11])
        ->and(gatesWithNotRunSteps(prGates()))->toBe([5, 7, 11]);
});

it('runs composer docs:check as gate 10, the single step, under the local profile\'s title', function (): void {
    $gate = prGates()[9];

    expect($gate->number)->toBe(10)
        ->and($gate->title)->toBe(LocalProfile::gates('/usr/bin/php', PR_COMPOSER)[9]->title)
        ->and(stepTriples($gate->steps))->toBe([
            ['docs:check', [...PR_COMPOSER, 'docs:check'], null],
        ])
        ->and($gate->steps[0]->runs())->toBeTrue()
        ->and($gate->steps[0]->ownProcessGroup)->toBeFalse()
        ->and($gate->steps[0]->reader)->toBeNull();
});

it('keeps gate 10 outside the local profile, whose gate 5 runs the same audit on the repository', function (): void {
    $gate = LocalProfile::gates('/usr/bin/php', PR_COMPOSER)[9];

    expect($gate->number)->toBe(10)
        ->and(stepTriples($gate->steps))->toBe([[$gate->title, [], LocalProfile::OUTSIDE_PROFILE]])
        ->and(LocalProfile::SUITES)->toContain('Unit')
        ->and(is_file(Phpstan::root().'/tests/Feature/Tooling/Docs/RepositoryDocsTest.php'))->toBeTrue();
});

it('fails gate 10 when composer docs:check has a finding', function (): void {
    $runner = new ScriptedProcessRunner(static fn (array $command): ProcessOutcome => array_last($command) === 'docs:check'
        ? new ProcessOutcome(1, "Cbox\\Cms\\Contracts\\Salutation: undocumented\n", 0.2)
        : new ProcessOutcome(0, composerAuditJson(), 0.1));

    $report = new CheckRunner($runner, new SilentListener)->run(prGates(), '/srv/checkout');

    expect($report->failedGates())->toBe([10])
        ->and($report->gate(10)?->step('docs:check')?->status)->toBe(StepStatus::Fail)
        ->and(ReportFormatter::summary($report))->toContain('composer check failed: gate 10 failed.');
});

it('builds the panel and runs the Browser suite as gate 8, in a process group of its own, failing skipped and incomplete tests as gate 5 does, and its browser matrix in Firefox and WebKit', function (): void {
    $gate = prGates()[7];
    $build = $gate->steps[0] ?? null;
    $step = $gate->steps[1] ?? null;
    $unit = array_first(array_filter(prGates()[4]->steps, static fn (Step $step): bool => $step->name === 'Unit'));
    $gate5Flags = array_slice($unit->command ?? [], 3, 2);

    expect($gate->number)->toBe(8)
        ->and($gate->steps)->toHaveCount(4)
        ->and($build?->name)->toBe('panel:build')
        ->and($build?->command)->toBe([...PR_COMPOSER, 'panel:build'])
        ->and($build?->ownProcessGroup)->toBeFalse()
        ->and($step?->name)->toBe('Browser')
        ->and($gate5Flags)->toBe(['--fail-on-skipped', '--fail-on-incomplete'])
        ->and(array_slice($unit->command ?? [], 5))->toBe(['--parallel'])
        ->and(LocalProfile::OTHER_SUITES)->toBe([PrProfile::BROWSER_SUITE, PrProfile::MUTATION_SUITE])
        ->and($step?->command)->toBe(['/usr/bin/php', 'vendor/bin/pest', '--testsuite=Browser', '--fail-on-skipped', '--fail-on-incomplete'])
        ->and(array_slice($step->command ?? [], 3))->toBe($gate5Flags)
        ->and($step?->ownProcessGroup)->toBeTrue()
        ->and($step?->reader)->toBeNull()
        ->and(stepTriples(array_slice($gate->steps, 2)))->toBe([
            ['Browser in Firefox', ['/usr/bin/php', 'vendor/bin/pest', '--testsuite=Browser', '--group=browser-matrix', '--browser', 'firefox', ...$gate5Flags], null],
            ['Browser in WebKit', ['/usr/bin/php', 'vendor/bin/pest', '--testsuite=Browser', '--group=browser-matrix', '--browser', 'safari', ...$gate5Flags], null],
        ])
        ->and(array_map(static fn (Step $matrix): bool => $matrix->ownProcessGroup, array_slice($gate->steps, 2)))->toBe([true, true]);
});

it('runs composer audit of the lock file and npm audit as gate 9, reading composer audit\'s JSON report', function (): void {
    $gate = prGates()[8];

    expect($gate->number)->toBe(9)
        ->and(stepTriples($gate->steps))->toBe([
            ['composer audit', [...PR_COMPOSER, 'audit', '--locked', '--abandoned=report', '--format=json'], null],
            ['npm audit', ['npm', 'audit'], null],
        ])
        ->and($gate->steps[0]->reader)->toBeInstanceOf(ComposerAuditReader::class)
        ->and($gate->steps[1]->reader)->toBeNull();
});

it('runs only the Browser steps in process groups of their own', function (): void {
    $grouped = [];

    foreach (prGates() as $gate) {
        foreach ($gate->steps as $step) {
            if ($step->ownProcessGroup) {
                $grouped[] = "{$gate->number} {$step->name}";
            }
        }
    }

    expect($grouped)->toBe(['8 Browser', '8 Browser in Firefox', '8 Browser in WebKit']);
});

it('fails gate 8 when the Browser suite fails, and runs it in its own process group', function (): void {
    $runner = new ScriptedProcessRunner(static fn (array $command): ProcessOutcome => in_array('--testsuite=Browser', $command, true)
        ? new ProcessOutcome(1, "FAILED  Tests\\Browser\\WorkbenchPageTest\n", 2.0)
        : new ProcessOutcome(0, composerAuditJson(), 0.1));

    $report = new CheckRunner($runner, new SilentListener)->run(prGates(), '/srv/checkout');
    $browser = array_values(array_filter($runner->calls, static fn (RecordedCommand $call): bool => in_array('--testsuite=Browser', $call->command, true)));

    expect($report->failedGates())->toBe([8])
        ->and($report->gate(8)?->step('Browser')?->status)->toBe(StepStatus::Fail)
        ->and($report->gate(8)?->step('Browser in Firefox')?->status)->toBe(StepStatus::Fail)
        ->and($report->gate(8)?->step('Browser in WebKit')?->status)->toBe(StepStatus::Fail)
        ->and($browser)->toHaveCount(3)
        ->and(array_map(static fn (RecordedCommand $call): bool => $call->ownProcessGroup, $browser))->toBe([true, true, true])
        ->and(array_filter($runner->calls, static fn (RecordedCommand $call): bool => $call->ownProcessGroup))->toHaveCount(3)
        ->and(ReportFormatter::summary($report))->toContain('composer check failed: gate 8 failed.');
});

it('fails gate 8 when the panel cannot be built, and still runs the Browser suite', function (): void {
    $runner = new ScriptedProcessRunner(static fn (array $command): ProcessOutcome => in_array('panel:build', $command, true)
        ? new ProcessOutcome(1, "vite: build failed\n", 1.0)
        : new ProcessOutcome(0, composerAuditJson(), 0.1));

    $report = new CheckRunner($runner, new SilentListener)->run(prGates(), '/srv/checkout');
    $browser = array_values(array_filter($runner->calls, static fn (RecordedCommand $call): bool => in_array('--testsuite=Browser', $call->command, true)));

    expect($report->failedGates())->toBe([8])
        ->and($report->gate(8)?->step('panel:build')?->status)->toBe(StepStatus::Fail)
        ->and($browser)->toHaveCount(3);
});

it('fails gate 8 when only the browser matrix fails in WebKit, and names the step', function (): void {
    $runner = new ScriptedProcessRunner(static fn (array $command): ProcessOutcome => in_array('safari', $command, true)
        ? new ProcessOutcome(1, "FAILED  Tests\\Browser\\Panel\\SharedExternalsTest\n", 2.0)
        : new ProcessOutcome(0, composerAuditJson(), 0.1));

    $report = new CheckRunner($runner, new SilentListener)->run(prGates(), '/srv/checkout');

    expect($report->failedGates())->toBe([8])
        ->and($report->gate(8)?->step('Browser')?->status)->toBe(StepStatus::Pass)
        ->and($report->gate(8)?->step('Browser in Firefox')?->status)->toBe(StepStatus::Pass)
        ->and($report->gate(8)?->step('Browser in WebKit')?->status)->toBe(StepStatus::Fail);
});

it('fails gate 9 on one security advisory from composer audit and names it', function (int $exitCode): void {
    $advisory = [
        'advisoryId' => 'PKSA-1234-abcd-5678',
        'packageName' => 'acme/parser',
        'affectedVersions' => '<1.2.3',
        'title' => 'Remote code execution in the parser',
        'cve' => 'CVE-2026-12345',
        'link' => 'https://example.test/advisory',
        'reportedAt' => '2026-09-01T00:00:00+00:00',
        'sources' => [['name' => 'GitHub', 'remoteId' => 'GHSA-xxxx-yyyy-zzzz']],
        'severity' => 'high',
    ];
    $report = runPrGates(composerAuditJson(advisories: ['acme/parser' => [$advisory]]), $exitCode);
    $step = $report->gate(9)?->step('composer audit');

    expect($report->failedGates())->toBe([9])
        ->and($step?->status)->toBe(StepStatus::Fail)
        ->and($step?->reason)->toBe('1 security advisory: acme/parser')
        ->and($step?->notes)->toBe(['advisory: acme/parser: Remote code execution in the parser, CVE-2026-12345, high'])
        ->and(ReportFormatter::summary($report))->toContain(
            'fail      composer audit: 1 security advisory: acme/parser',
            'advisory: acme/parser: Remote code execution in the parser, CVE-2026-12345, high',
        );
})->with(['composer\'s exit code 1' => 1, 'an exit code of 0 as well' => 0]);

it('fails gate 9 on a security advisory from npm audit', function (): void {
    $runner = new ScriptedProcessRunner(static fn (array $command): ProcessOutcome => match ($command) {
        ['npm', 'audit'] => new ProcessOutcome(1, "# npm audit report\n\nacme-parser  <1.2.3\nSeverity: high\n\n1 high severity vulnerability\n", 1.0),
        default => new ProcessOutcome(0, composerAuditJson(), 0.1),
    });

    $report = new CheckRunner($runner, new SilentListener)->run(prGates(), '/srv/checkout');

    expect($report->failedGates())->toBe([9])
        ->and($report->gate(9)?->step('composer audit')?->status)->toBe(StepStatus::Pass)
        ->and($report->gate(9)?->step('npm audit')?->status)->toBe(StepStatus::Fail);
});

it('passes gate 9 with only an abandoned package and lists the package in the summary and the report', function (): void {
    $report = runPrGates(composerAuditJson(abandoned: ['acme/legacy' => 'acme/modern', 'acme/orphan' => null]), 0);
    $step = $report->gate(9)?->step('composer audit');
    $notes = ['abandoned: acme/legacy, replaced by acme/modern', 'abandoned: acme/orphan, no replacement suggested'];

    expect($report->passed())->toBeTrue()
        ->and($report->gate(9)?->status())->toBe(StepStatus::Pass)
        ->and($step?->status)->toBe(StepStatus::Pass)
        ->and($step?->reason)->toBeNull()
        ->and($step?->notes)->toBe($notes)
        ->and(ReportFormatter::summary($report))->toContain(
            "           pass      composer audit\n                       {$notes[0]}\n                       {$notes[1]}\n           pass      npm audit\n",
        )
        ->and(ReportFormatter::stepLine($step ?? StepResult::notRun('none', 'none')))->toBe(
            "  pass      composer audit  0.1 s\n              {$notes[0]}\n              {$notes[1]}\n",
        )
        ->and(CheckReportJson::decode(CheckReportJson::encode($report))->gate(9)?->step('composer audit')?->notes)->toBe($notes);
});

it('picks the gates by profile and names the profile in the header', function (): void {
    $scope = MutationScope::changed('abc123', []);

    expect(Profile::Local->gates('/usr/bin/php', PR_COMPOSER))->toEqual(LocalProfile::gates('/usr/bin/php', PR_COMPOSER))
        ->and(Profile::Pr->gates('/usr/bin/php', PR_COMPOSER, $scope))->toEqual(prGates($scope))
        ->and(Profile::Pr->gates('/usr/bin/php', PR_COMPOSER, $scope, PrPart::all()))->toEqual(prGates($scope, PrPart::all()))
        ->and(Profile::Pr->gates('/usr/bin/php', PR_COMPOSER))->toEqual(prGates())
        ->and(static fn (): array => Profile::Pr->gates('/usr/bin/php', PR_COMPOSER, null, PrPart::all()))->toThrow(InvalidArgumentException::class)
        ->and(Profile::Pr->mutates())->toBeTrue()
        ->and(Profile::Local->mutates())->toBeFalse()
        ->and(ReportFormatter::header('/repo'))->toBe("composer check: the local profile of GUARDRAILS 10, gates 1 to 6, in /repo\n")
        ->and(ReportFormatter::header('/repo', Profile::Pr))->toBe("composer check: the PR profile of GUARDRAILS 10 as CI runs it today, gates 1 to 6, 8, 9 and 10, with 7 and 11 reported as not run, in /repo\n");
});

it('runs every gate but mutation on changed files in the gates job, and reports that step as run in the shards', function (): void {
    $scope = MutationScope::changed('abc123', [new ChangedSource('packages/contracts/src/Ids/PrincipalId.php', PrincipalId::class)]);
    $full = PrProfile::gates('/usr/bin/php', PR_COMPOSER, $scope, PrPart::all());
    $gates = PrProfile::gates('/usr/bin/php', PR_COMPOSER, $scope, PrPart::gates());
    $mutationSteps = count(MutationSteps::for($scope, '/usr/bin/php'));

    foreach ([1, 2, 3, 4, 6, 7, 8, 9, 10, 11] as $number) {
        expect($gates[$number - 1])->toEqual($full[$number - 1]);
    }

    expect(array_slice($gates[4]->steps, 0, -1))->toEqual(array_slice($full[4]->steps, 0, -$mutationSteps))
        ->and(array_last($gates[4]->steps)?->name)->toBe(MutationSteps::NAME)
        ->and(array_last($gates[4]->steps)?->notRunReason)->toBe(PrProfile::MUTATION_IN_SHARDS);
});

it('runs only its shard of mutation on changed files in a shard job, with the tally, and reports every other gate as run in the gates job', function (): void {
    $scope = MutationScope::changed('abc123 (shard 2 of 3)', [new ChangedSource('packages/contracts/src/Ids/PrincipalId.php', PrincipalId::class)]);
    $tally = new MutationTally;
    $shard = PrProfile::gates('/usr/bin/php', PR_COMPOSER, $scope, PrPart::shard(2, 3), $tally);

    expect(array_map(static fn (Gate $gate): int => $gate->number, $shard))->toBe(range(1, 11))
        ->and($shard[4]->steps)->toEqual(MutationSteps::for($scope, '/usr/bin/php', $tally))
        ->and($shard[4]->steps[1]->reader instanceof MutationReportReader ? $shard[4]->steps[1]->reader->tally : null)->toBe($tally);

    foreach ($shard as $gate) {
        if ($gate->number === 5) {
            continue;
        }

        expect($gate->steps)->toHaveCount(1)
            ->and($gate->steps[0]->notRunReason)->toBe(PrProfile::NOT_RUN[$gate->number] ?? PrProfile::GATE_IN_GATES_JOB);
    }
});

it('names the part in the header of a run of the PR profile, whether it runs mutation testing or not', function (): void {
    expect(ReportFormatter::header('/srv/checkout', Profile::Pr, PrPart::shard(2, 3)))->toContain('; this run: mutation on changed files, shard 2 of 3, in /srv/checkout')
        ->and(ReportFormatter::header('/srv/checkout', Profile::Pr, PrPart::gates()))->toContain('; this run: the gates, with mutation on changed files run in its shards')
        ->and(ReportFormatter::header('/srv/checkout', Profile::Pr, PrPart::all()))->toContain('; this run: every gate, with mutation testing, in /srv/checkout')
        ->and(ReportFormatter::header('/srv/checkout', Profile::Pr, PrPart::withoutMutation()))->toContain('; this run: every gate, without mutation testing, which is deferred until after v1, in /srv/checkout')
        ->and(ReportFormatter::header('/srv/checkout', Profile::Pr))->not->toContain('this run')
        ->and(ReportFormatter::header('/srv/checkout', Profile::Local, PrPart::all()))->not->toContain('this run');
});

it('runs the Mutation suite and mutation on changed files only in the parts that opt in to mutation testing', function (): void {
    expect([PrPart::withoutMutation()->runsGates(), PrPart::withoutMutation()->runsMutationSuite(), PrPart::withoutMutation()->runsMutation(), PrPart::withoutMutation()->mutationTesting])->toBe([true, false, false, false])
        ->and([PrPart::all()->runsGates(), PrPart::all()->runsMutationSuite(), PrPart::all()->runsMutation(), PrPart::all()->mutationTesting])->toBe([true, true, true, true])
        ->and([PrPart::gates()->runsGates(), PrPart::gates()->runsMutationSuite(), PrPart::gates()->runsMutation(), PrPart::gates()->mutationTesting])->toBe([true, true, false, true])
        ->and([PrPart::shard(1, 2)->runsGates(), PrPart::shard(1, 2)->runsMutationSuite(), PrPart::shard(1, 2)->runsMutation(), PrPart::shard(1, 2)->mutationTesting])->toBe([false, false, true, true]);
});
