<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Check;

use Cbox\Cms\Tooling\Check\Adapter\ConsoleListener;
use Cbox\Cms\Tooling\Check\Boundary\CheckReportJson;
use Cbox\Cms\Tooling\Check\Domain\CheckReport;
use Cbox\Cms\Tooling\Check\Domain\Gate;
use Cbox\Cms\Tooling\Check\Domain\GateResult;
use Cbox\Cms\Tooling\Check\Domain\ProcessOutcome;
use Cbox\Cms\Tooling\Check\Domain\ReportFormatter;
use Cbox\Cms\Tooling\Check\Domain\Step;
use Cbox\Cms\Tooling\Check\Domain\StepResult;
use Cbox\Cms\Tooling\Check\Domain\StepStatus;
use InvalidArgumentException;
use RuntimeException;
use UnexpectedValueException;

/*
 * What `composer check` prints and writes: every gate and step marked pass, fail or not run
 * with the reason, the output of a failed step, and the JSON report the selftest reads.
 */

function sampleReport(bool $postgresUp = true): CheckReport
{
    $pass = static fn (string $step): StepResult => StepResult::ran($step, new ProcessOutcome(0, "{$step} ok", 1.25));

    return new CheckReport('/srv/checkout', [
        new GateResult(1, 'Pint and Prettier', [$pass('Pint'), $pass('Prettier')]),
        new GateResult(3, 'PHPStan', [$pass('PHPStan')]),
        new GateResult(5, 'Pest', [
            $pass('Unit'),
            $postgresUp ? $pass('Postgres') : StepResult::ran('Postgres', new ProcessOutcome(1, "Start the services with `composer services:up`.\n", 1.0)),
            StepResult::notRun('Mutation', 'mutation testing is not set up'),
        ]),
        new GateResult(6, 'Generated code', [$pass('check:generated')]),
        new GateResult(8, 'Browser tests', [StepResult::notRun('Browser tests', 'not in the local profile')]),
    ]);
}

/**
 * @return resource
 */
function memoryStream(): mixed
{
    return fopen('php://memory', 'w+') ?: throw new RuntimeException('No memory stream.');
}

/**
 * @param  resource  $stream
 */
function streamText(mixed $stream): string
{
    rewind($stream);

    return (string) stream_get_contents($stream);
}

it('summarises every gate and every step of a gate with several steps, with the reasons for not running', function (): void {
    expect(ReportFormatter::summary(sampleReport()))->toBe(<<<'TEXT'

        Summary
          Gate 1   pass      Pint and Prettier
                   pass      Pint
                   pass      Prettier
          Gate 3   pass      PHPStan
          Gate 5   pass      Pest
                   pass      Unit
                   pass      Postgres
                   not run   Mutation: mutation testing is not set up
          Gate 6   pass      Generated code
          Gate 8   not run   Browser tests: not in the local profile

        composer check passed.

        TEXT);
});

it('marks gate 5 failed when the Postgres suite fails, and names the failed gates', function (): void {
    $summary = ReportFormatter::summary(sampleReport(postgresUp: false));

    expect($summary)->toContain("  Gate 5   fail      Pest\n", "           fail      Postgres\n")
        ->and($summary)->toEndWith("composer check failed: gate 5 failed.\n");
});

it('names several failed gates in order', function (): void {
    $fail = StepResult::ran('x', new ProcessOutcome(1, '', 0.0));
    $report = new CheckReport('/srv', [new GateResult(2, 'a', [$fail]), new GateResult(3, 'b', [$fail]), new GateResult(6, 'c', [$fail])]);

    expect(ReportFormatter::summary($report))->toEndWith("composer check failed: gates 2, 3 and 6 failed.\n");
});

it('prints each step as it finishes, with a failed step\'s output, and leaves gates outside the profile to the summary', function (bool $brief): void {
    $stream = memoryStream();
    $listener = new ConsoleListener($stream, $brief);
    $gate = new Gate(3, 'PHPStan', [Step::run('PHPStan', ['phpstan'])]);
    $outside = new Gate(8, 'Browser tests', [Step::notRun('Browser tests', 'not in the local profile')]);

    $listener->gateStarted($gate);
    $listener->stepFinished($gate, StepResult::ran('PHPStan', new ProcessOutcome(1, "packages/core/src/Bad.php:12 cboxCms.mixed\n", 4.44)));
    $listener->gateStarted($outside);
    $listener->stepFinished($outside, StepResult::notRun('Browser tests', 'not in the local profile'));
    $text = streamText($stream);

    expect($text)->toStartWith("\nGate 3  PHPStan\n  fail      PHPStan  4.4 s, exit code 1\n")
        ->and(str_contains($text, 'packages/core/src/Bad.php:12 cboxCms.mixed'))->toBe(! $brief)
        ->and($text)->not->toContain('Browser');
})->with(['full' => false, 'brief' => true]);

it('writes a JSON report with every gate and step and reads it back unchanged', function (): void {
    $report = sampleReport(postgresUp: false);
    $json = CheckReportJson::encode($report);
    $decoded = CheckReportJson::decode($json);

    expect(CheckReportJson::encode($decoded))->toBe($json)
        ->and($decoded->directory)->toBe('/srv/checkout')
        ->and($decoded->failedGates())->toBe([5])
        ->and($decoded->gate(5)?->step('Postgres')?->output)->toBe("Start the services with `composer services:up`.\n")
        ->and($decoded->gate(5)?->step('Mutation')?->status)->toBe(StepStatus::NotRun)
        ->and($json)->toContain('"passed": false', '"directory": "/srv/checkout"', '"format": 1');
});

it('refuses a report it cannot trust', function (string $json): void {
    expect(static fn (): CheckReport => CheckReportJson::decode($json))->toThrow(UnexpectedValueException::class);
})->with([
    'not JSON' => ['{'],
    'a list' => ['[]'],
    'another format' => ['{"directory": "/srv", "format": 2, "gates": []}'],
    'no directory' => ['{"format": 1, "gates": []}'],
    'a gate without a number' => ['{"directory": "/srv", "format": 1, "gates": [{"title": "a", "steps": []}]}'],
    'an unknown status' => ['{"directory": "/srv", "format": 1, "gates": [{"number": 1, "title": "a", "steps": [{"name": "a", "status": "skipped", "exit_code": 0, "output": "", "reason": null, "seconds": 0}]}]}'],
    'an exit code that is not an integer' => ['{"directory": "/srv", "format": 1, "gates": [{"number": 1, "title": "a", "steps": [{"name": "a", "status": "pass", "exit_code": "0", "output": "", "reason": null, "seconds": 0}]}]}'],
]);

it('refuses a step whose status does not match its exit code', function (StepStatus $status, ?int $exitCode, ?string $reason): void {
    expect(static fn (): StepResult => StepResult::restore('a', $status, $exitCode, '', 0.0, $reason))->toThrow(InvalidArgumentException::class);
})->with([
    'pass with exit code 1' => [StepStatus::Pass, 1, null],
    'fail with exit code 0' => [StepStatus::Fail, 0, null],
    'not run with an exit code' => [StepStatus::NotRun, 0, 'later'],
    'not run without a reason' => [StepStatus::NotRun, null, null],
    'pass without an exit code, a reason or a note' => [StepStatus::Pass, null, null],
    'pass without an exit code, with a reason' => [StepStatus::Pass, null, 'why'],
]);

it('writes a step decided without a command to the report and reads it back', function (): void {
    $report = new CheckReport('/srv/checkout', [new GateResult(5, 'Pest', [
        StepResult::decided('Mutation on changed files', StepStatus::Pass, '0 changed classes since abc'),
        StepResult::decided('Mutation, again', StepStatus::Fail, 'CMS_CI_BASE_REF is not set'),
    ])]);

    $decoded = CheckReportJson::decode(CheckReportJson::encode($report));

    expect($decoded)->toEqual($report)
        ->and($decoded->gate(5)?->step('Mutation on changed files')?->notes)->toBe(['0 changed classes since abc'])
        ->and($decoded->gate(5)?->step('Mutation, again')?->reason)->toBe('CMS_CI_BASE_REF is not set');
});

it('restores a step that failed with exit code 0 because its output reader found a failure, and its notes', function (): void {
    $restored = StepResult::restore('composer audit', StepStatus::Fail, 0, '{}', 0.1, '1 security advisory: acme/parser', ['advisory: acme/parser']);

    expect($restored->status)->toBe(StepStatus::Fail)
        ->and($restored->notes)->toBe(['advisory: acme/parser'])
        ->and(static fn (): StepResult => StepResult::restore('a', StepStatus::NotRun, null, '', 0.0, 'later', ['a note']))->toThrow(InvalidArgumentException::class)
        ->and(static fn (): StepResult => StepResult::restore('a', StepStatus::Pass, 0, '', 0.0, null, ["two\nlines"]))->toThrow(InvalidArgumentException::class);
});

it('writes the command of each step to the report and reads it back', function (): void {
    $report = new CheckReport('/srv/checkout', [new GateResult(5, 'Pest', [
        StepResult::ran('Unit', new ProcessOutcome(0, 'ok', 1.0), command: ['/usr/bin/php', 'vendor/bin/pest', '--testsuite=Unit', '--parallel']),
        StepResult::notRun('Mutation', 'mutation testing is not set up'),
    ])]);
    $json = CheckReportJson::encode($report);
    $decoded = CheckReportJson::decode($json);

    expect($decoded)->toEqual($report)
        ->and($decoded->gate(5)?->step('Unit')?->command)->toBe(['/usr/bin/php', 'vendor/bin/pest', '--testsuite=Unit', '--parallel'])
        ->and($decoded->gate(5)?->step('Mutation')?->command)->toBe([])
        ->and($json)->toContain('"--testsuite=Unit"');
});

it('reads a report without commands as steps without commands, and refuses a command that is not a list of strings', function (): void {
    $step = '{"name": "a", "status": "pass", "exit_code": 0, "output": "", "reason": null, "seconds": 0%s}';
    $report = static fn (string $command): string => '{"directory": "/srv", "format": 1, "gates": [{"number": 1, "title": "a", "steps": ['.sprintf($step, $command).']}]}';

    expect(CheckReportJson::decode($report(''))->gate(1)?->step('a')?->command)->toBe([])
        ->and(CheckReportJson::decode($report(', "command": ["pest", "--parallel"]'))->gate(1)?->step('a')?->command)->toBe(['pest', '--parallel'])
        ->and(static fn (): CheckReport => CheckReportJson::decode($report(', "command": [1]')))->toThrow(UnexpectedValueException::class)
        ->and(static fn (): CheckReport => CheckReportJson::decode($report(', "command": "pest"')))->toThrow(UnexpectedValueException::class);
});

it('reads a report without notes as steps without notes, and refuses notes that are not strings', function (): void {
    $step = '{"name": "a", "status": "pass", "exit_code": 0, "output": "", "reason": null, "seconds": 0%s}';
    $report = static fn (string $notes): string => '{"directory": "/srv", "format": 1, "gates": [{"number": 1, "title": "a", "steps": ['.sprintf($step, $notes).']}]}';

    expect(CheckReportJson::decode($report(''))->gate(1)?->step('a')?->notes)->toBe([])
        ->and(static fn (): CheckReport => CheckReportJson::decode($report(', "notes": [1]')))->toThrow(UnexpectedValueException::class)
        ->and(static fn (): CheckReport => CheckReportJson::decode($report(', "notes": "a"')))->toThrow(UnexpectedValueException::class);
});
