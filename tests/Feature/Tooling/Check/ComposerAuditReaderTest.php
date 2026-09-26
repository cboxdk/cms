<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Check;

use Cbox\Cms\Tooling\Check\Domain\ComposerAuditReader;
use Cbox\Cms\Tooling\Check\Domain\OutputReading;
use Cbox\Cms\Tooling\Check\Domain\ProcessOutcome;
use Cbox\Cms\Tooling\Check\Domain\StepResult;
use Cbox\Cms\Tooling\Check\Domain\StepStatus;
use InvalidArgumentException;

/*
 * The reader of gate 9's composer audit step: `composer audit --locked --abandoned=report
 * --format=json`. Any security advisory fails the step, abandoned packages are notes, and output
 * without the JSON report fails it.
 */

function readAudit(string $output, int $exitCode = 0): OutputReading
{
    return new ComposerAuditReader()->read(new ProcessOutcome($exitCode, $output, 0.1));
}

it('passes a clean report without notes', function (): void {
    $reading = readAudit("{\n    \"advisories\": [],\n    \"abandoned\": [],\n    \"filter\": []\n}\n");

    expect($reading->failure)->toBeNull()
        ->and($reading->notes)->toBe([]);
});

it('counts every advisory, names each package once and lists each advisory', function (): void {
    $reading = readAudit(json_encode([
        'advisories' => [
            'acme/parser' => [['title' => 'First', 'cve' => 'CVE-2026-1', 'severity' => 'low'], ['title' => "Second\non two lines", 'cve' => null, 'severity' => 'medium']],
            'acme/http' => [['title' => 'Third']],
        ],
        'abandoned' => [],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), 1);

    expect($reading->failure)->toBe('3 security advisories: acme/parser, acme/http')
        ->and($reading->notes)->toBe([
            'advisory: acme/parser: First, CVE-2026-1, low',
            'advisory: acme/parser: Second on two lines, medium',
            'advisory: acme/http: Third',
        ]);
});

it('reads the report between warnings Composer writes to standard error', function (): void {
    $output = "Warning: the lock file is not up to date with the latest changes in composer.json.\n"
        ."{\n    \"advisories\": {},\n    \"abandoned\": {\n        \"acme/legacy\": \"acme/modern\"\n    }\n}\n"
        ."Deprecation Notice: something in a plugin\n";

    expect(readAudit($output))->toEqual(new OutputReading(['abandoned: acme/legacy, replaced by acme/modern']));
});

it('fails output without the JSON report, also with exit code 0', function (string $output): void {
    $reading = readAudit($output);
    $result = StepResult::ran('composer audit', new ProcessOutcome(0, $output, 0.1), $reading);

    expect($reading->failure)->toBe('composer audit printed no JSON report with advisories and abandoned packages')
        ->and($result->status)->toBe(StepStatus::Fail)
        ->and($result->exitCode)->toBe(0)
        ->and($result->reason)->toBe($reading->failure);
})->with([
    'nothing' => [''],
    'the plain table' => ["No security vulnerability advisories found.\n"],
    'broken JSON' => ["{\n    \"advisories\": [\n}\n"],
    'JSON without abandoned packages' => ["{\n    \"advisories\": []\n}\n"],
]);

it('keeps a note to one line and a failure to a reason', function (): void {
    expect(static fn (): OutputReading => new OutputReading(["two\nlines"]))->toThrow(InvalidArgumentException::class)
        ->and(static fn (): OutputReading => new OutputReading(['']))->toThrow(InvalidArgumentException::class)
        ->and(static fn (): OutputReading => new OutputReading([], ''))->toThrow(InvalidArgumentException::class);
});
