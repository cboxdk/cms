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
use Cbox\Cms\Tooling\Check\Domain\PrProfile;
use Cbox\Cms\Tooling\Check\Domain\ReportFormatter;
use Cbox\Cms\Tooling\Check\Domain\Step;
use Cbox\Cms\Tooling\Check\Domain\StepResult;
use Cbox\Cms\Tooling\Check\Domain\StepStatus;
use Cbox\Cms\Tooling\Mutation\Domain\ChangedSource;
use Cbox\Cms\Tooling\Mutation\Domain\MutationScope;
use Cbox\Cms\Tooling\Mutation\Domain\MutationSteps;
use InvalidArgumentException;

/*
 * The PR profile as CI runs it through bin/ci (`composer check -- --pr`): the steps of gates 1 to
 * 6 are the local profile's, so CI and a developer run the same commands, and gate 5 adds the
 * Mutation suite and mutation on changed files; gate 8 runs the Browser suite in a process group
 * of its own, gate 9 runs composer audit and npm audit and gate 10 runs composer docs:check;
 * everything else of the PR profile in GUARDRAILS 10 is reported as not run with a reason. The runs here use the scripted process
 * runner; BrowserStepTest runs a Browser step for real, tests/Mutation a mutation step.
 */

const PR_COMPOSER = ['/usr/bin/php', '/usr/bin/composer'];

/**
 * @return list<Gate>
 */
function prGates(?MutationScope $mutation = null): array
{
    return PrProfile::gates('/usr/bin/php', PR_COMPOSER, $mutation ?? MutationScope::changed('abc123', []));
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
 * @param  list<Step>  $steps
 * @return list<array{string, list<string>, ?string}>
 */
function stepTriples(array $steps): array
{
    return array_map(static fn (Step $step): array => [$step->name, $step->command, $step->notRunReason], $steps);
}

it('runs the local profile\'s steps for gates 1 to 6, unchanged, and adds only the Mutation suite and mutation on changed files to gate 5', function (): void {
    $local = LocalProfile::gates('/usr/bin/php', PR_COMPOSER);
    $scope = MutationScope::changed('abc123', [new ChangedSource('packages/contracts/src/Ids/PrincipalId.php', PrincipalId::class)]);
    $pr = prGates($scope);

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

it('runs mutation on changed files in gate 5 and never reports it as not run: a missing base fails it, no change passes it', function (): void {
    $unresolved = prGates(MutationScope::unresolved('CMS_CI_BASE_REF is not set'))[4];
    $unchanged = prGates(MutationScope::changed('abc123', []))[4];

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

    expect(array_unique(PrProfile::NOT_RUN))->toHaveCount(2)
        ->and(array_map(static fn (Gate $gate): int => $gate->number, array_values(array_filter(prGates(), static fn (Gate $gate): bool => array_any($gate->steps, static fn (Step $step): bool => $step->notRunReason !== null)))))->toBe([7, 11]);
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

it('runs the Browser suite as gate 8, in a process group of its own, failing skipped and incomplete tests as gate 5 does', function (): void {
    $gate = prGates()[7];
    $step = $gate->steps[0] ?? null;
    $gate5Flags = array_slice(prGates()[4]->steps[0]->command, 3);

    expect($gate->number)->toBe(8)
        ->and($gate->steps)->toHaveCount(1)
        ->and($step?->name)->toBe('Browser')
        ->and(LocalProfile::OTHER_SUITES)->toBe([PrProfile::BROWSER_SUITE, PrProfile::MUTATION_SUITE])
        ->and($step?->command)->toBe(['/usr/bin/php', 'vendor/bin/pest', '--testsuite=Browser', '--fail-on-skipped', '--fail-on-incomplete'])
        ->and(array_slice($step->command ?? [], 3))->toBe($gate5Flags)
        ->and($step?->ownProcessGroup)->toBeTrue()
        ->and($step?->reader)->toBeNull();
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

it('runs only the Browser step in a process group of its own', function (): void {
    $grouped = [];

    foreach (prGates() as $gate) {
        foreach ($gate->steps as $step) {
            if ($step->ownProcessGroup) {
                $grouped[] = "{$gate->number} {$step->name}";
            }
        }
    }

    expect($grouped)->toBe(['8 Browser']);
});

it('fails gate 8 when the Browser suite fails, and runs it in its own process group', function (): void {
    $runner = new ScriptedProcessRunner(static fn (array $command): ProcessOutcome => in_array('--testsuite=Browser', $command, true)
        ? new ProcessOutcome(1, "FAILED  Tests\\Browser\\WorkbenchPageTest\n", 2.0)
        : new ProcessOutcome(0, composerAuditJson(), 0.1));

    $report = new CheckRunner($runner, new SilentListener)->run(prGates(), '/srv/checkout');
    $browser = array_values(array_filter($runner->calls, static fn (RecordedCommand $call): bool => in_array('--testsuite=Browser', $call->command, true)));

    expect($report->failedGates())->toBe([8])
        ->and($report->gate(8)?->step('Browser')?->status)->toBe(StepStatus::Fail)
        ->and($browser)->toHaveCount(1)
        ->and($browser[0]->ownProcessGroup ?? null)->toBeTrue()
        ->and(array_filter($runner->calls, static fn (RecordedCommand $call): bool => $call->ownProcessGroup))->toHaveCount(1)
        ->and(ReportFormatter::summary($report))->toContain('composer check failed: gate 8 failed.');
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
        ->and(static fn (): array => Profile::Pr->gates('/usr/bin/php', PR_COMPOSER))->toThrow(InvalidArgumentException::class)
        ->and(Profile::Pr->mutates())->toBeTrue()
        ->and(Profile::Local->mutates())->toBeFalse()
        ->and(ReportFormatter::header('/repo'))->toBe("composer check: the local profile of GUARDRAILS 10, gates 1 to 6, in /repo\n")
        ->and(ReportFormatter::header('/repo', Profile::Pr))->toBe("composer check: the PR profile of GUARDRAILS 10 as CI runs it today, gates 1 to 6 with mutation on changed files, 8, 9 and 10, with 7 and 11 reported as not run, in /repo\n");
});
