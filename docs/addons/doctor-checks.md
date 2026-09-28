---
title: Doctor checks
weight: 37
description: Add a check of your own to cms:doctor, keep the DoctorCheck contract, and test the check with the shared suite DoctorCheckContract and the fake FakeDoctorCheck.
---

# Doctor checks

<!-- extension-point: Cbox\Cms\Contracts\Doctor\DoctorCheck -->
<!-- extension-point: Cbox\Cms\Testkit\Doctor\DoctorCheckContract -->

An application or addon adds its own checks to `cms:doctor`. This page covers the contract a check keeps, `Cbox\Cms\Contracts\Doctor\DoctorCheck`, how a check is added, and how it is tested with the testkit's shared suite `Cbox\Cms\Testkit\Doctor\DoctorCheckContract` and its fake `FakeDoctorCheck`. All of them are `#[Experimental]`. What the doctor runs, how it reads the results and the exit codes are on [cms:doctor](../developers/doctor.md).

## Adding a check

An application or addon adds its own checks by class name in two lists of the core's configuration:

- `cbox-cms.doctor.checks`: the checks run after the core's runtime checks, in the order of the list, with and without `--dev`.
- `cbox-cms.doctor.dev_checks`: the checks run only with `--dev`, after the core's development checks, in the order of the list.

An application sets them in its `config/cbox-cms.php`, for example `'doctor' => ['checks' => [UploadsDirectoryCheck::class]]`. An addon ships the check class and names it in its installation guide, so the application decides which checks its doctor runs. Both lists are empty by default.

The container builds each check when the doctor makes its list, so a check gets what it looks at through its constructor, as the contract asks: bind a class it needs, or give a value such as a path with a contextual binding in a service provider's `register()`, for example `$this->app->when(UploadsDirectoryCheck::class)->needs('$directory')->give(storage_path('uploads'))`.

The added checks keep the same rules as the core's:

- every id is unique, among the core's checks too;
- a check requires only checks that run before it. A check in `checks` may require any of the core's runtime checks, such as `postgres.reachable`, and a check before it in the list. A check in `dev_checks` may also require the core's development checks and every check in `checks`. A check in `checks` cannot require a development check, because it also runs without `--dev`;
- a blocking check requires only blocking checks. A check that does not block, such as `partitions.runway`, can fail while the kernel starts, and the doctor would then skip the blocking check that needs it and exit 79, so the kernel would start without the blocking check having looked. A check that does not block may require checks of either kind.

A problem with an added check is a configuration problem, and the doctor reports it as the failing check `doctor.config` in place of every other check, with a cause that names the setting:

- the list is not a list, or an entry is not the name of a class that implements `DoctorCheck`, as in `The setting cbox-cms.doctor.checks.0 must be the name of a class that implements Cbox\Cms\Contracts\Doctor\DoctorCheck; it is 'stdClass'.`;
- the container cannot build the class, a binding gives something that does not implement `DoctorCheck`, or the check's `id()`, `blocking()` or `requires()` throws, as for an invalid `CheckId`;
- an id repeats, a check requires one that does not run before it, or a blocking check requires one that does not block.

Once it is in the list, an added check runs like the core's: the doctor skips it when a check it requires did not pass, its failure counts in the exit code, and a `run()` that breaks the contract fails as `doctor_check_crashed`.

This example adds the check from the end of this page, `UploadsDirectoryCheck`, and gives it its directory with a contextual binding. It runs `cms:doctor --json` and finds the check last among the runtime checks, before the development checks of `--dev`, with its own code, cause and fix when the directory is missing. It asserts only the added check, because the results of the core's checks depend on the host running it. It is in the `Postgres` suite, which needs `composer services:up`:

<!-- example: examples/Postgres/Doctor/AddCheckTest.php -->
```php
<?php

declare(strict_types=1);

use Examples\Contract\Doctor\UploadsDirectoryCheck;
use Illuminate\Support\Facades\Artisan;

// Adds UploadsDirectoryCheck to cms:doctor the way an application does it: the class name in
// cbox-cms.doctor.checks, which an application sets in its config/cbox-cms.php, and a contextual binding for
// the directory, which it makes in a service provider's register(). The container builds the check,
// and the doctor runs it after the core's runtime checks. The test asserts only the added check and
// the order, because the results of the core's checks depend on the host running it.

/**
 * Runs cms:doctor --json and returns the ids of the checks in the document, in the order they
 * ran, and the added check's entry.
 *
 * @param  array<string, bool>  $options
 * @return array{list<string>, array<string, mixed>}
 */
function doctorWithUploads(string $directory, array $options = []): array
{
    config(['cbox-cms.doctor.checks' => [UploadsDirectoryCheck::class]]);
    app()->when(UploadsDirectoryCheck::class)->needs('$directory')->give($directory);

    Artisan::call('cms:doctor', ['--json' => true, ...$options]);
    $document = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    $checks = is_array($document) && is_array($document['checks'] ?? null) ? $document['checks'] : [];
    $ids = [];
    $added = [];

    foreach ($checks as $check) {
        if (is_array($check) && is_string($check['id'] ?? null)) {
            $ids[] = $check['id'];

            if ($check['id'] === UploadsDirectoryCheck::ID) {
                /** @var array<string, mixed> $check */
                $added = $check;
            }
        }
    }

    return [$ids, $added];
}

it('runs the added check after the core\'s runtime checks and reports it in --json', function (): void {
    [$ids, $uploads] = doctorWithUploads(sys_get_temp_dir());

    expect(array_last($ids))->toBe(UploadsDirectoryCheck::ID)
        ->and($uploads['status'])->toBe('pass')
        ->and($uploads['blocking'])->toBeFalse()
        ->and($uploads['explanation'])->toBe(sprintf('Uploads can be written to %s.', sys_get_temp_dir()));
});

it('keeps the added check before the development checks of --dev', function (): void {
    [$ids] = doctorWithUploads(sys_get_temp_dir(), ['--dev' => true]);

    expect(array_slice($ids, -4))->toBe([UploadsDirectoryCheck::ID, 'dev.node', 'dev.playwright', 'dev.chromium']);
});

it('reports the failure of the added check with its own code, cause and fix', function (): void {
    $missing = sys_get_temp_dir().'/cbox-cms-example-uploads-that-do-not-exist';

    [, $uploads] = doctorWithUploads($missing);

    expect($uploads['status'])->toBe('fail')
        ->and($uploads['failure'])->toBe('violation')
        ->and($uploads['code'])->toBe(UploadsDirectoryCheck::CODE_MISSING)
        ->and($uploads['cause'])->toBe(sprintf('%s does not exist or is not a directory.', $missing))
        ->and($uploads['fix'])->toBe(sprintf('Create %s and give the user that runs PHP write access to it.', $missing));
});
```

## The contract: DoctorCheck

A check has four methods.

- `id(): CheckId` is the check's stable name, such as `postgres.reachable`: two or more lowercase snake_case segments separated by dots, at most 63 characters (`CheckId::MAX_LENGTH`). A bad id throws `InvalidDoctorCheck` when the `CheckId` is made. The id is part of the JSON document, so a check keeps its id.
- `blocking(): bool` says whether the kernel refuses to start while the check fails. A check that is not blocking only affects readiness, so a cold start never blocks itself: the partition runway, for example, is extended by the scheduler of the started application. Its failure alone makes the doctor exit 79, not ready.
- `requires(): list<CheckId>` lists the checks that must pass before this one runs, each once and never the check itself. A check can rely on what a requirement showed, such as a reachable Postgres. A requirement is always a check that runs earlier, and the requirement of a blocking check is always a blocking check.
- `run(): CheckResult` looks at the part it checks and returns the result.

`run()` only looks. It changes nothing, so running it twice in the same state gives the same result, and it never throws. A dependency that cannot be reached, or a setting that is wrong, is a result, not an exception. It returns one of two results, and each carries the check's own id and blocking:

- `CheckResult::pass($id, $blocking, $explanation)`: the part is in order. The explanation says what the check looked at and what it found.
- `CheckResult::fail($id, $blocking, $failure, $code, $explanation, $cause, $fix)`: the part is not in order. `$failure` is a `FailureKind`. `$code` is the error code: lowercase snake_case with at least two words and at most 63 characters (`CheckResult::CODE_PATTERN` and `CheckResult::MAX_CODE_LENGTH`), such as `doctor_postgres_unavailable`. `$cause` is the concrete cause, such as the value that was found, and `$fix` says what to do about it. An error that only says what went wrong is a bug in the error.

For a blocking check, the `FailureKind` decides the exit code; the failure of a check that does not block gives 79 whatever its kind, unless a blocking check fails too:

- `Violation`: the configuration is invalid or the runtime contract is broken. Trying again does not help; someone has to change something.
- `Unavailable`: a dependency such as Postgres or Valkey cannot be reached right now, and nothing is misconfigured. Trying again later may help.

The constructor of `CheckResult` enforces what goes with each status: a pass has no failure kind, code, cause or fix; a failure has all four; every text has at least one character that is not white space. A result that breaks this throws `InvalidDoctorCheck`.

## Testing a check: DoctorCheckContract and FakeDoctorCheck

Every check runs the shared suite, the trait `Cbox\Cms\Testkit\Doctor\DoctorCheckContract` from the testkit of `cboxdk/cms`, in a PHPUnit test class in its package's `tests/Contract` directory. The trait has two abstract methods, and both return the same check: `passingDoctorCheck()` in a state where `run()` passes, and `failingDoctorCheck()` in a state where it fails. A check that looks at the outside world, such as a database or a file, therefore gets what it looks at through its constructor, so that a test can hand in both states. The core's checks ask small interfaces of their own, and their contract tests hand in fakes of them.

The suite checks that:

- the check has the same id, blocking and requirements in both states, and every time it is asked;
- it does not require itself and lists each requirement once;
- the passing state returns a pass with the check's own id and blocking, a non-empty explanation, and no failure kind, code, cause or fix;
- the failing state returns a failure with the check's own id and blocking, a failure kind, a code that matches `CheckResult::CODE_PATTERN`, and a cause and a fix that are not empty, where the fix is not the cause again and the cause is not the explanation again;
- running the check again in either state gives an equal result.

A `run()` that throws fails the test and names the exception, because a check returns a failure instead.

`Cbox\Cms\Testkit\Doctor\FakeDoctorCheck` is the fake of the contract, for code that works with checks and their results, as in the example of the [exit codes](../developers/doctor.md#exit-codes-doctorexitcode). `FakeDoctorCheck::passing($id)` and `FakeDoctorCheck::failing($id, $failure)` make one, both with `blocking` and `requires` as named arguments. A failing fake fails with the code `FakeDoctorCheck::CODE`, `fake_check_failed`, and a cause and fix that name the check. `passes()` and `fails($failure)` change the outcome of the next run, for a test that repairs or breaks the state, and `runs()` says how often `run()` was called. The testkit runs the shared suite against the fake itself.

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
