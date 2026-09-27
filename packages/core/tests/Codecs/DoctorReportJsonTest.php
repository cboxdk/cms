<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Codecs;

use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Core\Doctor\Boundary\DoctorReportJson;
use Cbox\Cms\Core\Doctor\Domain\Dto\DoctorReport;
use Cbox\Cms\Core\Tests\Doctor\DoctorSchema;

/*
 * The document of `cms:doctor --json`, version 1, against its committed JSON Schema in the
 * contracts package (GUARDRAILS 2.6), with an independent validator: every report the encoder can
 * give validates, and the schema refuses documents that break the rules of a result.
 */

function passed(string $id, bool $blocking = true): CheckResult
{
    return CheckResult::pass(new CheckId($id), $blocking, 'It is fine.');
}

function failedAs(string $id, FailureKind $kind, bool $blocking = true): CheckResult
{
    return CheckResult::fail(new CheckId($id), $blocking, $kind, 'doctor_test_failure', 'It is broken.', 'Because of this.', 'Do that.');
}

function skipped(string $id): CheckResult
{
    return CheckResult::skip(new CheckId($id), true, 'Not run.', 'a.first did not pass.');
}

/**
 * @return array<string, mixed>
 */
function decoded(string $json): array
{
    $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    expect($document)->toBeArray();

    /** @var array<string, mixed> $document */
    return $document;
}

/**
 * Checks that every object in the document has its keys in sorted order.
 */
function assertDoctorKeysSorted(mixed $value): void
{
    if (! is_array($value)) {
        return;
    }

    if (! array_is_list($value)) {
        $keys = array_map(strval(...), array_keys($value));
        $sorted = $keys;
        sort($sorted, SORT_STRING);

        expect($keys)->toBe($sorted);
    }

    foreach ($value as $inner) {
        assertDoctorKeysSorted($inner);
    }
}

it('encodes every kind of report so that it validates against doctor.v1.json', function (DoctorReport $report, string $status, int $exit): void {
    $json = DoctorReportJson::encode($report);
    $document = decoded($json);

    expect(DoctorSchema::errors($json))->toBe([])
        ->and($document['status'])->toBe($status)
        ->and($document['exit_code'])->toBe($exit)
        ->and($document['version'])->toBe(1);

    assertDoctorKeysSorted($document);
})->with([
    'all pass' => [new DoctorReport(false, [passed('a.first'), passed('b.second', false)]), 'ok', 0],
    'pass and skip' => [new DoctorReport(true, [passed('a.first'), skipped('b.second')]), 'ok', 0],
    'unavailable' => [new DoctorReport(false, [failedAs('a.first', FailureKind::Unavailable), skipped('b.second')]), 'unavailable', 75],
    'violation' => [new DoctorReport(false, [passed('a.first'), failedAs('b.second', FailureKind::Violation)]), 'violation', 78],
    'both' => [new DoctorReport(true, [failedAs('a.first', FailureKind::Unavailable), failedAs('b.second', FailureKind::Violation)]), 'violation', 78],
    'violation with readiness failures' => [new DoctorReport(false, [failedAs('a.first', FailureKind::Unavailable, false), failedAs('b.second', FailureKind::Violation), failedAs('c.third', FailureKind::Violation, false)]), 'violation', 78],
    'unavailable with a readiness violation' => [new DoctorReport(false, [failedAs('a.first', FailureKind::Unavailable), failedAs('b.second', FailureKind::Violation, false)]), 'unavailable', 75],
    'a readiness violation' => [new DoctorReport(false, [passed('a.first'), failedAs('b.second', FailureKind::Violation, false)]), 'not_ready', 79],
    'a readiness unavailable' => [new DoctorReport(true, [passed('a.first'), failedAs('b.second', FailureKind::Unavailable, false)]), 'not_ready', 79],
    'readiness failures and a skip' => [new DoctorReport(true, [passed('a.first'), failedAs('b.second', FailureKind::Unavailable, false), failedAs('c.third', FailureKind::Violation, false), skipped('d.fourth')]), 'not_ready', 79],
]);

it('writes the exact bytes of version 1', function (): void {
    $report = new DoctorReport(false, [passed('php.version'), failedAs('postgres.reachable', FailureKind::Unavailable), skipped('postgres.version')]);

    expect(DoctorReportJson::encode($report))->toBe(<<<'JSON'
        {
            "checks": [
                {
                    "blocking": true,
                    "cause": null,
                    "code": null,
                    "explanation": "It is fine.",
                    "failure": null,
                    "fix": null,
                    "id": "php.version",
                    "status": "pass"
                },
                {
                    "blocking": true,
                    "cause": "Because of this.",
                    "code": "doctor_test_failure",
                    "explanation": "It is broken.",
                    "failure": "unavailable",
                    "fix": "Do that.",
                    "id": "postgres.reachable",
                    "status": "fail"
                },
                {
                    "blocking": true,
                    "cause": "a.first did not pass.",
                    "code": null,
                    "explanation": "Not run.",
                    "failure": null,
                    "fix": null,
                    "id": "postgres.version",
                    "status": "skip"
                }
            ],
            "dev": false,
            "exit_code": 75,
            "status": "unavailable",
            "version": 1
        }

        JSON)
        ->and(DoctorReportJson::encode($report))->toBe(DoctorReportJson::encode($report));
});

it('keeps paths and non-ASCII text readable', function (): void {
    $json = DoctorReportJson::encode(new DoctorReport(false, [CheckResult::pass(new CheckId('registry.cache'), true, 'Built in /srv/app/bootstrap/cache/cms, ÆØÅ.')]));

    expect($json)->toContain('/srv/app/bootstrap/cache/cms, ÆØÅ.');
});

it('has a schema that refuses documents breaking the rules of a result', function (string $json, string $pointer): void {
    expect(DoctorSchema::errors($json))->toHaveKey($pointer);
})->with([
    'no checks' => ['{"checks":[],"dev":false,"exit_code":0,"status":"ok","version":1}', '/checks'],
    'unknown version' => ['{"checks":[{"blocking":true,"cause":null,"code":null,"explanation":"x","failure":null,"fix":null,"id":"a.b","status":"pass"}],"dev":false,"exit_code":0,"status":"ok","version":2}', '/version'],
    'extra key' => ['{"checks":[{"blocking":true,"cause":null,"code":null,"explanation":"x","failure":null,"fix":null,"id":"a.b","status":"pass"}],"dev":false,"exit_code":0,"extra":1,"status":"ok","version":1}', '/'],
    'missing key in a check' => ['{"checks":[{"blocking":true,"cause":null,"code":null,"explanation":"x","failure":null,"id":"a.b","status":"pass"}],"dev":false,"exit_code":0,"status":"ok","version":1}', '/checks/0'],
    'a pass with a code' => ['{"checks":[{"blocking":true,"cause":null,"code":"doctor_x","explanation":"x","failure":null,"fix":null,"id":"a.b","status":"pass"}],"dev":false,"exit_code":0,"status":"ok","version":1}', '/checks/0/code'],
    'a fail without a fix' => ['{"checks":[{"blocking":true,"cause":"c","code":"doctor_x","explanation":"x","failure":"violation","fix":null,"id":"a.b","status":"fail"}],"dev":false,"exit_code":78,"status":"violation","version":1}', '/checks/0/fix'],
    'a skip without a cause' => ['{"checks":[{"blocking":true,"cause":null,"code":null,"explanation":"x","failure":null,"fix":null,"id":"a.b","status":"skip"}],"dev":false,"exit_code":0,"status":"ok","version":1}', '/checks/0/cause'],
    'ok with a failure' => ['{"checks":[{"blocking":true,"cause":"c","code":"doctor_x","explanation":"x","failure":"violation","fix":"f","id":"a.b","status":"fail"}],"dev":false,"exit_code":0,"status":"ok","version":1}', '/checks/0/failure'],
    'violation without one' => ['{"checks":[{"blocking":true,"cause":"c","code":"doctor_x","explanation":"x","failure":"unavailable","fix":"f","id":"a.b","status":"fail"}],"dev":false,"exit_code":78,"status":"violation","version":1}', '/checks/0/failure'],
    'violation only from a readiness check' => ['{"checks":[{"blocking":false,"cause":"c","code":"doctor_x","explanation":"x","failure":"violation","fix":"f","id":"a.b","status":"fail"}],"dev":false,"exit_code":78,"status":"violation","version":1}', '/checks/0/blocking'],
    'unavailable only from a readiness check' => ['{"checks":[{"blocking":false,"cause":"c","code":"doctor_x","explanation":"x","failure":"unavailable","fix":"f","id":"a.b","status":"fail"}],"dev":false,"exit_code":75,"status":"unavailable","version":1}', '/checks/0/blocking'],
    'unavailable with a blocking violation' => ['{"checks":[{"blocking":true,"cause":"c","code":"doctor_x","explanation":"x","failure":"unavailable","fix":"f","id":"a.b","status":"fail"},{"blocking":true,"cause":"c","code":"doctor_x","explanation":"x","failure":"violation","fix":"f","id":"a.c","status":"fail"}],"dev":false,"exit_code":75,"status":"unavailable","version":1}', '/checks/1/failure'],
    'not_ready with a blocking failure' => ['{"checks":[{"blocking":false,"cause":"c","code":"doctor_x","explanation":"x","failure":"violation","fix":"f","id":"a.b","status":"fail"},{"blocking":true,"cause":"c","code":"doctor_x","explanation":"x","failure":"unavailable","fix":"f","id":"a.c","status":"fail"}],"dev":false,"exit_code":79,"status":"not_ready","version":1}', '/checks/1/failure'],
    'not_ready without a readiness failure' => ['{"checks":[{"blocking":false,"cause":null,"code":null,"explanation":"x","failure":null,"fix":null,"id":"a.b","status":"pass"}],"dev":false,"exit_code":79,"status":"not_ready","version":1}', '/checks/0/failure'],
    'not_ready with exit code 0' => ['{"checks":[{"blocking":false,"cause":"c","code":"doctor_x","explanation":"x","failure":"violation","fix":"f","id":"a.b","status":"fail"}],"dev":false,"exit_code":0,"status":"not_ready","version":1}', '/exit_code'],
    'an invalid id' => ['{"checks":[{"blocking":true,"cause":null,"code":null,"explanation":"x","failure":null,"fix":null,"id":"php","status":"pass"}],"dev":false,"exit_code":0,"status":"ok","version":1}', '/checks/0/id'],
    'an empty explanation' => ['{"checks":[{"blocking":true,"cause":null,"code":null,"explanation":" ","failure":null,"fix":null,"id":"a.b","status":"pass"}],"dev":false,"exit_code":0,"status":"ok","version":1}', '/checks/0/explanation'],
]);
