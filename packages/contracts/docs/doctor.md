# Doctor

<!-- extension-point: Cbox\Cms\Contracts\Doctor\DoctorCheck -->
<!-- extension-point: Cbox\Cms\Testkit\Doctor\DoctorCheckContract -->
<!-- extension-point: packages/contracts/resources/schemas/doctor.v1.json -->

`cms:doctor` checks the installation and the runtime contract (PRD 3.3, 4.2, 13.2). For each part it says whether it is in order, and for each problem what is wrong and how to fix it (GUARDRAILS 7.1). `--dev` adds the development tools, and `--json` prints only a document that a deploy script or a readiness probe reads. The process exits with a fixed code: ok, a violation, or a dependency that is unavailable right now.

This page covers the contract a check keeps, `Cbox\Cms\Contracts\Doctor\DoctorCheck`, how the doctor turns the results into an exit code, the document of `--json`, described by the JSON Schema [`doctor.v1.json`](../resources/schemas/doctor.v1.json), and how a check is tested with the testkit's shared suite `Cbox\Cms\Testkit\Doctor\DoctorCheckContract` and its fake `FakeDoctorCheck`. All of them are `#[Experimental]`.

## Which checks run

In milestone 0 the checks of `cms:doctor` are the core's own list, which `CoreServiceProvider` in `cboxdk/cms-core` builds. A package or an application cannot add a check to `cms:doctor` yet, and there is no configuration for it. A class that implements `DoctorCheck` outside the core, such as the example at the end of this page, keeps the contract and runs through the shared suite, but `cms:doctor` does not run it.

The doctor runs the checks in this order. The last three run only with `--dev`. A check that is not blocking only affects readiness: the kernel still starts while it fails.

| Id | Blocking | Requires | What it looks at |
|---|---|---|---|
| `php.version` | yes | | PHP 8.5 or newer |
| `php.allow_url_fopen` | yes | | `allow_url_fopen` is off, so outbound HTTP goes only through the egress gateway |
| `laravel.version` | yes | | Laravel 13 |
| `postgres.reachable` | yes | | Postgres answers on the app role's connection |
| `postgres.version` | yes | `postgres.reachable` | Postgres 17 or newer |
| `postgres.app_role` | yes | `postgres.reachable` | the app role is not a superuser, has NOBYPASSRLS and NOCREATEROLE, and is not a member of a role with more power |
| `postgres.transaction_timeout` | yes | `postgres.version` | `transaction_timeout` is above zero and set on the app role |
| `postgres.prepared_transactions` | yes | `postgres.reachable` | `max_prepared_transactions` is 0 |
| `postgres.lc_messages` | yes | `postgres.reachable` | the messages of Postgres and libpq are English |
| `postgres.ddl_privileges` | yes | `postgres.reachable` | the app role owns nothing and cannot create objects |
| `postgres.row_security` | yes | `postgres.reachable` | every table with row level security also forces it |
| `valkey.reachable` | yes | | Valkey answers PING |
| `partitions.runway` | no | `postgres.reachable` | every partitioned table has partitions far enough ahead of the clock |
| `registry.cache` | yes | | the registry cache of `cms:build` exists and is not older than `vendor/` |
| `postgres.owner_credentials` | no | | only the maintenance process holds the owner role's credentials |
| `dev.node` | no | | Node on the PATH, at least the configured minimum |
| `dev.playwright` | no | `dev.node` | Playwright is installed in the project |
| `dev.chromium` | no | `dev.playwright` | Playwright's Chromium is downloaded |

When the doctor's own settings, `cms.doctor`, are invalid, the doctor runs none of these. It runs the single check `doctor.config` instead, which fails as a violation with the code `doctor_config_invalid`, so the command still prints its document and exits with the violation code.

## The contract: DoctorCheck

A check has four methods.

- `id(): CheckId` is the check's stable name, such as `postgres.reachable`: two or more lowercase snake_case segments separated by dots, at most 63 characters (`CheckId::MAX_LENGTH`). A bad id throws `InvalidDoctorCheck` when the `CheckId` is made. The id is part of the JSON document, so a check keeps its id.
- `blocking(): bool` says whether the kernel refuses to start while the check fails. A check that is not blocking only affects readiness, so a cold start never blocks itself: the partition runway, for example, is extended by the scheduler of the started application.
- `requires(): list<CheckId>` lists the checks that must pass before this one runs, each once and never the check itself. A check can rely on what a requirement showed, such as a reachable Postgres. A requirement is always a check that runs earlier.
- `run(): CheckResult` looks at the part it checks and returns the result.

`run()` only looks. It changes nothing, so running it twice in the same state gives the same result, and it never throws. A dependency that cannot be reached, or a setting that is wrong, is a result, not an exception. It returns one of two results, and each carries the check's own id and blocking:

- `CheckResult::pass($id, $blocking, $explanation)`: the part is in order. The explanation says what the check looked at and what it found.
- `CheckResult::fail($id, $blocking, $failure, $code, $explanation, $cause, $fix)`: the part is not in order. `$failure` is a `FailureKind`. `$code` is the error code: lowercase snake_case with at least two words and at most 63 characters (`CheckResult::CODE_PATTERN` and `CheckResult::MAX_CODE_LENGTH`), such as `doctor_postgres_unavailable`. `$cause` is the concrete cause, such as the value that was found, and `$fix` says what to do about it. An error that only says what went wrong is a bug in the error.

The `FailureKind` decides the exit code:

- `Violation`: the configuration is invalid or the runtime contract is broken. Trying again does not help; someone has to change something.
- `Unavailable`: a dependency such as Postgres or Valkey cannot be reached right now, and nothing is misconfigured. Trying again later may help.

The constructor of `CheckResult` enforces what goes with each status: a pass has no failure kind, code, cause or fix; a failure has all four; every text has at least one character that is not white space. A result that breaks this throws `InvalidDoctorCheck`.

## Skips and crashes

A check never returns a skip. Only the doctor skips a check: when a check it requires did not pass, the doctor does not run it and reports it with the status skip and a cause that names the requirement, such as `postgres.reachable did not pass.`. A skip is not a failure and does not change the exit code; the failure of the requirement already does.

The doctor always gives a complete report and never exits ok for a check that did not look. A check that breaks its contract fails as a `Violation` with the code `doctor_check_crashed`, and its cause says how it broke the contract. That covers a `run()` that throws, a result for another id or with another blocking than the check's own, and a skip returned from `run()`. The fix says it is a bug in the check.

## Exit codes: DoctorExitCode

The exit codes of `cms:doctor` are defined in one place, the enum `Cbox\Cms\Contracts\Doctor\DoctorExitCode`. `status()` gives the name the JSON document uses.

| Case | Exit code | `status()` | When |
|---|---|---|---|
| `Ok` | 0 | `ok` | every check passed or was skipped |
| `Unavailable` | 75, `EX_TEMPFAIL` of sysexits.h | `unavailable` | at least one check failed as `Unavailable`, and none as `Violation` |
| `Violation` | 78, `EX_CONFIG` of sysexits.h | `violation` | at least one check failed as `Violation` |

A violation wins over an unavailable dependency, because waiting does not fix it. Every failure counts, blocking or not: a check that does not block the kernel from starting still makes the doctor exit with its failure's code, and the document says which failures block. `DoctorExitCode::for($results)` adds up a list of `CheckResult`s this way. M1 folds these codes into the error catalog.

This example adds up the results of the testkit's `FakeDoctorCheck`, which passes or fails as the test says. It is in the `Unit` suite:

<!-- example: examples/Unit/Doctor/ExitCodeTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\DoctorExitCode;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Testkit\Doctor\FakeDoctorCheck;

// How cms:doctor adds up its exit code from the results of its checks, with the testkit's
// FakeDoctorCheck standing in for real checks: a violation wins over an unavailable dependency,
// and every failure counts, also that of a check that does not block the kernel from starting.

it('exits ok when every check passes', function (): void {
    $results = [
        FakeDoctorCheck::passing(new CheckId('example.first'))->run(),
        FakeDoctorCheck::passing(new CheckId('example.second'), blocking: false)->run(),
    ];

    expect(DoctorExitCode::for($results))->toBe(DoctorExitCode::Ok)
        ->and(DoctorExitCode::Ok->value)->toBe(0)
        ->and(DoctorExitCode::Ok->status())->toBe('ok');
});

it('exits unavailable when a dependency cannot be reached, even from a check that does not block', function (): void {
    $results = [
        FakeDoctorCheck::passing(new CheckId('example.first'))->run(),
        FakeDoctorCheck::failing(new CheckId('example.cache'), FailureKind::Unavailable, blocking: false)->run(),
    ];

    expect(DoctorExitCode::for($results))->toBe(DoctorExitCode::Unavailable)
        ->and(DoctorExitCode::Unavailable->value)->toBe(75)
        ->and(DoctorExitCode::Unavailable->status())->toBe('unavailable');
});

it('exits violation when one check is violated, whatever else is unavailable', function (): void {
    $results = [
        FakeDoctorCheck::failing(new CheckId('example.database'), FailureKind::Unavailable)->run(),
        FakeDoctorCheck::failing(new CheckId('example.setting'), FailureKind::Violation, blocking: false)->run(),
        FakeDoctorCheck::failing(new CheckId('example.cache'), FailureKind::Unavailable)->run(),
    ];

    expect(DoctorExitCode::for($results))->toBe(DoctorExitCode::Violation)
        ->and(DoctorExitCode::Violation->value)->toBe(78)
        ->and(DoctorExitCode::Violation->status())->toBe('violation');
});

it('lets a test repair or break a fake check between runs, and counts the runs', function (): void {
    $check = FakeDoctorCheck::failing(new CheckId('example.setting'));

    expect($check->run()->code)->toBe(FakeDoctorCheck::CODE);

    $check->passes();

    expect($check->run()->passed())->toBeTrue()
        ->and($check->runs())->toBe(2);
});
```

## The document of cms:doctor --json

`cms:doctor --json` prints one JSON document and nothing else, and exits with the code the document names. The JSON Schema [`doctor.v1.json`](../resources/schemas/doctor.v1.json) (draft 2020-12) describes it; an installed application finds it at `vendor/cboxdk/cms-contracts/resources/schemas/doctor.v1.json`. Every key is always present, a value that does not apply is `null`, and the keys are sorted. The schema allows no other keys. A change that is not backwards compatible gets a new schema file.

The document has five keys:

| Key | Type | Meaning |
|---|---|---|
| `checks` | array of check objects, at least one | the checks in the order they ran |
| `dev` | boolean | whether the development checks of `--dev` ran |
| `exit_code` | integer, 0 to 255 | the exit code of the process, the value of `DoctorExitCode` |
| `status` | `ok`, `violation` or `unavailable` | the name of the exit code, `DoctorExitCode::status()` |
| `version` | the number `1` | the version of the document |

Each check object has eight keys:

| Key | Type | Meaning |
|---|---|---|
| `blocking` | boolean | whether the kernel refuses to start while this check fails |
| `cause` | text or `null` | for a failure, the concrete cause; for a skip, the requirement that did not pass; otherwise `null` |
| `code` | error code or `null` | for a failure, the error code, lowercase snake_case with at least two words and at most 63 characters; otherwise `null` |
| `explanation` | text | what the check looked at and what it found, in plain words |
| `failure` | `violation`, `unavailable` or `null` | for a failure, its `FailureKind`; otherwise `null` |
| `fix` | text or `null` | for a failure, how to fix it; otherwise `null` |
| `id` | check id | the stable id of the check, such as `postgres.reachable` |
| `status` | `pass`, `fail` or `skip` | the outcome; skip when a check it requires did not pass |

Text is a string with at least one character that is not white space. The schema also holds the values to each other:

- A check with the status `pass` has `null` for `failure`, `code`, `cause` and `fix`.
- A check with the status `fail` has a `failure`, and text for `code`, `cause` and `fix`.
- A check with the status `skip` has text for `cause`, and `null` for `failure`, `code` and `fix`.
- The status `ok` has the exit code 0, and no check has a `failure`.
- The status `violation` has an exit code of at least 1, and at least one check failed as `violation`.
- The status `unavailable` has an exit code of at least 1, at least one check failed as `unavailable`, and no check failed as `violation`.

This example runs the public command through Artisan against the services of the test environment, with and without `--dev`, validates the document with opis/json-schema and checks that `exit_code` is the command's exit code. It asserts no particular status, because that depends on the host running it, for example on `allow_url_fopen` in its php.ini. It is in the `Postgres` suite, which needs `composer services:up`:

<!-- example: examples/Postgres/Doctor/DoctorJsonTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Doctor\DoctorExitCode;
use Illuminate\Support\Facades\Artisan;
use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;

// Runs cms:doctor --json against the services of the test environment and validates what it prints
// against doctor.v1.json from the installed cboxdk/cms-contracts. Decode the document without the
// associative flag, so that a JSON object stays an object for the validator. The test asserts no
// particular status: that depends on the host, for example on allow_url_fopen in its php.ini.

it('prints a document that doctor.v1.json accepts, and exits with its exit_code', function (array $options, bool $dev): void {
    $schema = file_get_contents(dirname(__DIR__, 3).'/vendor/cboxdk/cms-contracts/resources/schemas/doctor.v1.json')
        ?: throw new RuntimeException('Cannot read doctor.v1.json.');

    $exitCode = Artisan::call('cms:doctor', ['--json' => true, ...$options]);
    $document = json_decode(Artisan::output(), false, 512, JSON_THROW_ON_ERROR);

    if (! $document instanceof stdClass) {
        throw new UnexpectedValueException('cms:doctor --json printed no JSON object.');
    }

    $error = new CompliantValidator()->validate($document, $schema)->error();

    expect($error instanceof ValidationError ? new ErrorFormatter()->format($error) : [])->toBe([])
        ->and($document->exit_code)->toBe($exitCode)
        ->and($document->status)->toBe(DoctorExitCode::from($exitCode)->status())
        ->and($document->dev)->toBe($dev);
})->with([
    'the runtime checks' => [[], false],
    'with the development checks of --dev' => [['--dev' => true], true],
]);
```

## Testing a check: DoctorCheckContract and FakeDoctorCheck

Every check runs the shared suite, the trait `Cbox\Cms\Testkit\Doctor\DoctorCheckContract` from `cboxdk/cms-testkit`, in a PHPUnit test class in its package's `tests/Contract` directory. The trait has two abstract methods, and both return the same check: `passingDoctorCheck()` in a state where `run()` passes, and `failingDoctorCheck()` in a state where it fails. A check that looks at the outside world, such as a database or a file, therefore gets what it looks at through its constructor, so that a test can hand in both states. The core's checks ask small interfaces of their own, and their contract tests hand in fakes of them.

The suite checks that:

- the check has the same id, blocking and requirements in both states, and every time it is asked;
- it does not require itself and lists each requirement once;
- the passing state returns a pass with the check's own id and blocking, a non-empty explanation, and no failure kind, code, cause or fix;
- the failing state returns a failure with the check's own id and blocking, a failure kind, a code that matches `CheckResult::CODE_PATTERN`, and a cause and a fix that are not empty, where the fix is not the cause again and the cause is not the explanation again;
- running the check again in either state gives an equal result.

A `run()` that throws fails the test and names the exception, because a check returns a failure instead.

`Cbox\Cms\Testkit\Doctor\FakeDoctorCheck` is the fake of the contract, for code that works with checks and their results, as in the example of the exit codes above. `FakeDoctorCheck::passing($id)` and `FakeDoctorCheck::failing($id, $failure)` make one, both with `blocking` and `requires` as named arguments. A failing fake fails with the code `FakeDoctorCheck::CODE`, `fake_check_failed`, and a cause and fix that name the check. `passes()` and `fails($failure)` change the outcome of the next run, for a test that repairs or breaks the state, and `runs()` says how often `run()` was called. The testkit runs the shared suite against the fake itself.

The example check below looks at the directory that uploads are written to. It gets the directory through its constructor and fails as a violation, with its own code, when the directory is missing or read-only:

<!-- example-file: examples/Contract/Doctor/UploadsDirectoryCheck.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Contract\Doctor;

use Cbox\Cms\Contracts\Doctor\CheckId;
use Cbox\Cms\Contracts\Doctor\CheckResult;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Override;

/**
 * A doctor check: the directory that uploads are written to exists and this process may write to
 * it. It only looks, with is_dir() and is_writable(), and never creates the directory: repairing
 * is for the operator, whom the fix tells how.
 *
 * It does not block the kernel from starting. Without the directory an upload fails, but every
 * other request is served, so the check only affects readiness.
 */
final readonly class UploadsDirectoryCheck implements DoctorCheck
{
    public const string ID = 'uploads.directory';

    public const string CODE_MISSING = 'uploads_directory_missing';

    public const string CODE_READ_ONLY = 'uploads_directory_read_only';

    public function __construct(private string $directory) {}

    #[Override]
    public function id(): CheckId
    {
        return new CheckId(self::ID);
    }

    #[Override]
    public function blocking(): bool
    {
        return false;
    }

    #[Override]
    public function requires(): array
    {
        return [];
    }

    #[Override]
    public function run(): CheckResult
    {
        if (! is_dir($this->directory)) {
            return CheckResult::fail(
                $this->id(),
                $this->blocking(),
                FailureKind::Violation,
                self::CODE_MISSING,
                'Uploads are written to a directory that must exist before the application starts.',
                sprintf('%s does not exist or is not a directory.', $this->directory),
                sprintf('Create %s and give the user that runs PHP write access to it.', $this->directory),
            );
        }

        if (! is_writable($this->directory)) {
            return CheckResult::fail(
                $this->id(),
                $this->blocking(),
                FailureKind::Violation,
                self::CODE_READ_ONLY,
                'Uploads are written to a directory that this process must be able to write to.',
                sprintf('%s exists, but this process may not write to it.', $this->directory),
                sprintf('Give the user that runs PHP write access to %s.', $this->directory),
            );
        }

        return CheckResult::pass($this->id(), $this->blocking(), sprintf('Uploads can be written to %s.', $this->directory));
    }
}
```

The test class uses the trait: the system's temporary directory is the passing state and a directory that does not exist the failing one. It also tests the check's own code for the failure. It is in the `Contract` suite:

<!-- example: examples/Contract/Doctor/UploadsDirectoryDoctorCheckContractTest.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Contract\Doctor;

use Cbox\Cms\Contracts\Doctor\CheckStatus;
use Cbox\Cms\Contracts\Doctor\DoctorCheck;
use Cbox\Cms\Testkit\Doctor\DoctorCheckContract;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The shared DoctorCheck suite against UploadsDirectoryCheck: passing on the system's temporary
 * directory, which exists and is writable, and failing on a directory that does not exist. The
 * class also tests the check's own code for the failure.
 */
final class UploadsDirectoryDoctorCheckContractTest extends TestCase
{
    use DoctorCheckContract;

    #[Override]
    protected function passingDoctorCheck(): DoctorCheck
    {
        return new UploadsDirectoryCheck(sys_get_temp_dir());
    }

    #[Override]
    protected function failingDoctorCheck(): DoctorCheck
    {
        return new UploadsDirectoryCheck($this->missingDirectory());
    }

    #[Test]
    public function a_missing_directory_is_a_violation_that_names_the_directory(): void
    {
        $directory = $this->missingDirectory();
        $result = new UploadsDirectoryCheck($directory)->run();

        self::assertSame(CheckStatus::Fail, $result->status);
        self::assertSame(UploadsDirectoryCheck::CODE_MISSING, $result->code);
        self::assertStringContainsString($directory, (string) $result->cause);
        self::assertFalse($result->blocking);
    }

    private function missingDirectory(): string
    {
        return sys_get_temp_dir().'/cbox-cms-example-uploads-that-do-not-exist';
    }
}
```
