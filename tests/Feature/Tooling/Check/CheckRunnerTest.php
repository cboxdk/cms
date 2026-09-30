<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Check;

use Cbox\Cms\Tests\Support\Tooling\RecordedCommand;
use Cbox\Cms\Tests\Support\Tooling\ScriptedProcessRunner;
use Cbox\Cms\Tooling\Check\Domain\CheckRunner;
use Cbox\Cms\Tooling\Check\Domain\Gate;
use Cbox\Cms\Tooling\Check\Domain\GateResult;
use Cbox\Cms\Tooling\Check\Domain\ProcessOutcome;
use Cbox\Cms\Tooling\Check\Domain\Step;
use Cbox\Cms\Tooling\Check\Domain\StepPrecheck;
use Cbox\Cms\Tooling\Check\Domain\StepResult;
use Cbox\Cms\Tooling\Check\Domain\StepStatus;
use InvalidArgumentException;

/*
 * The gate runner behind `composer check` (GUARDRAILS 10): every gate and step runs in order,
 * also after a failure, a step outside the profile is reported with its reason and never run,
 * and a gate's status follows its steps.
 */

/**
 * @return list<Gate>
 */
function sampleGates(): array
{
    return [
        new Gate(1, 'Format', [Step::run('Pint', ['pint']), Step::run('Prettier', ['prettier'])]),
        new Gate(2, 'Analyse', [Step::run('PHPStan', ['phpstan'])]),
        new Gate(5, 'Pest', [Step::run('Unit', ['pest', 'Unit']), Step::notRun('Mutation', 'mutation testing is not set up')]),
        new Gate(7, 'Storybook', [Step::notRun('Storybook', 'not in the local profile')]),
    ];
}

it('runs every step of every gate in order in the checked directory, also after a failure', function (): void {
    $runner = new ScriptedProcessRunner(static fn (array $command): ProcessOutcome => new ProcessOutcome(
        $command === ['pint'] ? 1 : 0,
        $command === ['pint'] ? 'packages/core/src/Bad.php' : 'ok',
        0.5,
    ));
    $listener = new RecordingListener;

    $report = new CheckRunner($runner, $listener)->run(sampleGates(), '/srv/checkout');

    expect($runner->commandLines())->toBe(['pint', 'prettier', 'phpstan', 'pest Unit'])
        ->and(array_unique(array_map(static fn (RecordedCommand $call): string => $call->directory, $runner->calls)))->toBe(['/srv/checkout'])
        ->and($listener->events)->toBe([
            'gate 1', 'Pint fail', 'Prettier pass',
            'gate 2', 'PHPStan pass',
            'gate 5', 'Unit pass', 'Mutation not run',
            'gate 7', 'Storybook not run',
        ])
        ->and($report->directory)->toBe('/srv/checkout')
        ->and($report->passed())->toBeFalse()
        ->and($report->failedGates())->toBe([1])
        ->and($report->gate(1)?->step('Pint')?->output)->toBe('packages/core/src/Bad.php')
        ->and($report->gate(1)?->step('Pint')?->exitCode)->toBe(1);
});

it('never runs a step that is not in the profile and reports it with its reason', function (): void {
    $runner = ScriptedProcessRunner::passing();

    $report = new CheckRunner($runner, new RecordingListener)->run(sampleGates(), '/srv/checkout');
    $mutation = $report->gate(5)?->step('Mutation');

    expect($runner->commandLines())->not->toContain('Mutation', 'Storybook')
        ->and($mutation?->status)->toBe(StepStatus::NotRun)
        ->and($mutation?->reason)->toBe('mutation testing is not set up')
        ->and($mutation?->exitCode)->toBeNull()
        ->and($report->gate(5)?->status())->toBe(StepStatus::Pass)
        ->and($report->gate(7)?->status())->toBe(StepStatus::NotRun)
        ->and($report->gate(7)?->notRunReason())->toBe('not in the local profile')
        ->and($report->passed())->toBeTrue();
});

it('records the command each step ran in its result, and none for a step that ran none', function (): void {
    $report = new CheckRunner(ScriptedProcessRunner::passing(), new RecordingListener)->run(sampleGates(), '/srv/checkout');

    expect($report->gate(1)?->step('Pint')?->command)->toBe(['pint'])
        ->and($report->gate(5)?->step('Unit')?->command)->toBe(['pest', 'Unit'])
        ->and($report->gate(5)?->step('Mutation')?->command)->toBe([])
        ->and($report->gate(7)?->step('Storybook')?->command)->toBe([]);
});

it('lifts Composer\'s process timeout for every command', function (): void {
    $runner = ScriptedProcessRunner::passing();

    new CheckRunner($runner, new RecordingListener)->run(sampleGates(), '/srv/checkout');

    expect($runner->calls)->not->toBeEmpty();

    foreach ($runner->calls as $call) {
        expect($call->environment)->toBe(['COMPOSER_PROCESS_TIMEOUT' => '0']);
    }
});

it('sets a step\'s own variables on top of the runner\'s, and only for that step', function (): void {
    $runner = ScriptedProcessRunner::passing();
    $gates = [new Gate(5, 'Pest', [
        Step::run('Unit', ['pest', 'Unit']),
        Step::run('Mutation', ['pest', '--mutate'], environment: ['PHP_INI_SCAN_DIR' => ':tools/mutation', 'COMPOSER_PROCESS_TIMEOUT' => '5']),
    ])];

    new CheckRunner($runner, new RecordingListener)->run($gates, '/srv/checkout');

    expect(array_map(static fn (RecordedCommand $call): array => $call->environment, $runner->calls))->toBe([
        ['COMPOSER_PROCESS_TIMEOUT' => '0'],
        ['COMPOSER_PROCESS_TIMEOUT' => '5', 'PHP_INI_SCAN_DIR' => ':tools/mutation'],
    ]);
});

it('reports a step decided without a command, a pass with its note and a fail with its reason, and runs nothing for it', function (): void {
    $runner = ScriptedProcessRunner::passing();
    $listener = new RecordingListener;
    $gates = [
        new Gate(5, 'Pest', [Step::run('Unit', ['pest', 'Unit']), Step::passed('Mutation', '0 changed classes since abc')]),
        new Gate(6, 'Other', [Step::failed('Mutation', 'CMS_CI_BASE_REF is not set')]),
    ];

    $report = new CheckRunner($runner, $listener)->run($gates, '/srv/checkout');
    $passed = $report->gate(5)?->step('Mutation');
    $failed = $report->gate(6)?->step('Mutation');

    expect($runner->commandLines())->toBe(['pest Unit'])
        ->and($listener->events)->toBe(['gate 5', 'Unit pass', 'Mutation pass', 'gate 6', 'Mutation fail'])
        ->and($passed?->status)->toBe(StepStatus::Pass)
        ->and($passed?->notes)->toBe(['0 changed classes since abc'])
        ->and($passed?->exitCode)->toBeNull()
        ->and($passed?->reason)->toBeNull()
        ->and($failed?->status)->toBe(StepStatus::Fail)
        ->and($failed?->reason)->toBe('CMS_CI_BASE_REF is not set')
        ->and($failed?->exitCode)->toBeNull()
        ->and($report->failedGates())->toBe([6])
        ->and(Step::passed('a', 'why')->runs())->toBeFalse()
        ->and(Step::failed('a', 'why')->runs())->toBeFalse()
        ->and(Step::notRun('a', 'why')->runs())->toBeFalse()
        ->and(Step::run('a', ['a'])->runs())->toBeTrue();
});

it('asks a step\'s precheck when the steps before it have run, and passes the step with its note without running it, or runs it on null', function (): void {
    $runner = new ScriptedProcessRunner(static fn (array $command): ProcessOutcome => new ProcessOutcome(0, implode(' ', $command), 0.1));
    $precheck = static fn (string $unless): StepPrecheck => new readonly class($runner, $unless) implements StepPrecheck
    {
        public function __construct(private ScriptedProcessRunner $runner, private string $unless) {}

        public function passedWithout(): ?string
        {
            return in_array($this->unless, $this->runner->commandLines(), true) ? 'decided by '.$this->unless : null;
        }
    };
    $gates = [new Gate(5, 'Pest', [
        Step::run('Fast', ['pest', 'fast']),
        Step::run('Postgres', ['pest', 'postgres'], precheck: $precheck('pest fast')),
        Step::run('Other', ['pest', 'other'], precheck: $precheck('pest nothing')),
    ])];

    $report = new CheckRunner($runner, new RecordingListener)->run($gates, '/srv/checkout');
    $skipped = $report->gate(5)?->step('Postgres');

    expect($runner->commandLines())->toBe(['pest fast', 'pest other'])
        ->and($skipped?->status)->toBe(StepStatus::Pass)
        ->and($skipped?->notes)->toBe(['decided by pest fast'])
        ->and($skipped?->exitCode)->toBeNull()
        ->and($report->gate(5)?->step('Other')?->exitCode)->toBe(0)
        ->and(Step::run('a', ['a'], precheck: $precheck('pest fast'))->runs())->toBeTrue();
});

it('fails a step that timed out, was killed or did not start, whatever its output', function (?int $exitCode, bool $timedOut): void {
    $runner = new ScriptedProcessRunner(static fn (): ProcessOutcome => new ProcessOutcome($exitCode, 'all good', 1.0, $timedOut));

    $report = new CheckRunner($runner, new RecordingListener)->run([new Gate(3, 'PHPStan', [Step::run('PHPStan', ['phpstan'])])], '/srv');

    expect($report->gate(3)?->status())->toBe(StepStatus::Fail)
        ->and($report->passed())->toBeFalse();
})->with([
    'timed out' => [null, true],
    'no exit code' => [null, false],
    'exit code 255' => [255, false],
]);

it('gives a gate the status of its steps: fail beats pass, pass beats not run', function (): void {
    $pass = StepResult::ran('a', new ProcessOutcome(0, '', 0.0));
    $fail = StepResult::ran('b', new ProcessOutcome(2, '', 0.0));
    $notRun = StepResult::notRun('c', 'later');

    expect(new GateResult(1, 'g', [$pass, $notRun])->status())->toBe(StepStatus::Pass)
        ->and(new GateResult(1, 'g', [$notRun, $fail, $pass])->status())->toBe(StepStatus::Fail)
        ->and(new GateResult(1, 'g', [$notRun])->status())->toBe(StepStatus::NotRun)
        ->and(new GateResult(1, 'g', [$pass, $notRun])->notRunReason())->toBeNull();
});

it('refuses gates and steps that cannot be reported', function (callable $make): void {
    expect($make)->toThrow(InvalidArgumentException::class);
})->with([
    'a gate without steps' => [static fn (): Gate => new Gate(1, 'Empty', [])],
    'gate number 0' => [static fn (): Gate => new Gate(0, 'Zero', [Step::run('a', ['a'])])],
    'a step named twice' => [static fn (): Gate => new Gate(1, 'Twice', [Step::run('a', ['a']), Step::run('a', ['b'])])],
    'a step without a command' => [static fn (): Step => Step::run('a', [])],
    'a step not run without a reason' => [static fn (): Step => Step::notRun('a', '')],
    'a step without a name' => [static fn (): Step => Step::run('', ['a'])],
    'a variable that is not a variable name' => [static fn (): Step => Step::run('a', ['a'], environment: ['NOT A NAME' => '1'])],
    'a pass without a note' => [static fn (): Step => Step::passed('a', '')],
    'a pass with a note of two lines' => [static fn (): Step => Step::passed('a', "two\nlines")],
    'a fail without a reason' => [static fn (): Step => Step::failed('a', '')],
    'a decided result that is not run' => [static fn (): StepResult => StepResult::decided('a', StepStatus::NotRun, 'why')],
]);
