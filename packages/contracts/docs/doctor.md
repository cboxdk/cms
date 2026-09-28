# Doctor

<!-- extension-point: Cbox\Cms\Contracts\Doctor\DoctorCheck -->
<!-- extension-point: Cbox\Cms\Testkit\Doctor\DoctorCheckContract -->
<!-- extension-point: packages/contracts/resources/schemas/doctor.v1.json -->

`cms:doctor` checks the installation and the runtime contract (PRD 3.3, 4.2, 13.2). For each part it says whether it is in order, and for each problem what is wrong and how to fix it (GUARDRAILS 7.1). `--dev` adds the development tools, and `--json` prints only a document that a deploy script or a readiness probe reads. The process exits with a fixed code: ok; a violation or a dependency that is unavailable right now, both from a check that blocks the kernel from starting; or not ready, when only checks that affect readiness fail.

This page covers the contract a check keeps, `Cbox\Cms\Contracts\Doctor\DoctorCheck`, how an application or addon adds a check to `cms:doctor`, how the doctor turns the results into an exit code, the document of `--json`, described by the JSON Schema [`doctor.v1.json`](../resources/schemas/doctor.v1.json), and how a check is tested with the testkit's shared suite `Cbox\Cms\Testkit\Doctor\DoctorCheckContract` and its fake `FakeDoctorCheck`. All of them are `#[Experimental]`.

## Which checks run

`cms:doctor` runs the core's own checks, which `CoreServiceProvider` in `cboxdk/cms-core` builds, and after them the checks an application or addon adds in `cbox-cms.doctor.checks` and `cbox-cms.doctor.dev_checks` (see [Adding a check](#adding-a-check)).

The core's checks run in this order. The last three run only with `--dev`. A check that is not blocking only affects readiness: the kernel still starts while it fails, and when no blocking check fails the doctor exits 79, not ready.

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

When the doctor's own settings, `cbox-cms.doctor`, are invalid, or a check added there cannot be used, the doctor runs none of these. It runs the single check `doctor.config` instead, which fails as a violation with the code `doctor_config_invalid` and names the setting in its cause, so the command still prints its document and exits with the violation code.

## Processes: web, queue and maintenance

An installation runs the kernel in three types of process. They run the same code against the same database, but only one of them holds the owner role's credentials (PRD 4.2). The app role on the default connection owns no tables and has no DDL, so code in the web and queue processes, an addon's included, cannot change the schema or pass row level security as the owner of the tables. Run `cms:doctor` in each of them, with that process's configuration.

| Process | What it runs | Its database connections | `CBOX_CMS_MAINTENANCE_PROCESS` in its environment |
|---|---|---|---|
| Web | HTTP requests: PHP-FPM, `php artisan serve` or Octane | the default connection, as the app role | unset |
| Queue | queued jobs: `php artisan queue:work`, `queue:listen` or Horizon | the default connection, as the app role | unset |
| Maintenance | the migrations on the owner connection, `php artisan migrate --database=pgsql_owner`, and the scheduler, `php artisan schedule:work` or `php artisan schedule:run` every minute, which runs `cms:partitions:maintain` every hour | the default connection, and the owner role's connection that `cbox-cms.database.owner_connection` names, `pgsql_owner` by default | `true` |

- **Only the maintenance process has the owner connection.** Leave `database.connections.pgsql_owner` and the owner's password out of the web and queue processes' configuration. They must not share a configuration cache (`php artisan config:cache`) with the maintenance process: build the maintenance process's cache in its own container or release, with the owner connection, and the web and queue processes' cache without it.
- **A web or queue process with the owner connection does not boot.** The core decides from the process itself what it runs, never from the configuration: a process that PHP does not run in the console, or that Octane started (`LARAVEL_OCTANE` in its environment), serves HTTP, and a console process whose command is `queue:work`, `queue:listen`, `horizon`, `horizon:supervisor` or `horizon:work` runs queued jobs. Such a process with the owner connection configured stops while it boots with `OwnerCredentialsExposed` (`[owner_credentials_exposed]`), before it runs a request or a job, so a shared configuration cache that holds the owner's credentials takes the web and queue processes down instead of handing the credentials to their code.
- **The maintenance process declares itself in its environment.** Set `CBOX_CMS_MAINTENANCE_PROCESS=true` in the environment of the maintenance process alone, such as its container definition, never in `.env` or the configuration, which the processes may share. The doctor reads `true` or `1` as declared and `false`, `0` or unset as not; any other value fails `doctor.config`. `postgres.owner_credentials` fails in a console process that has the owner connection unless that variable declares it, so a `cms:doctor` run in the web or queue container, whose configuration was given the owner's credentials by mistake, reports the process as not ready.
- **The maintenance process serves no HTTP and runs no queue worker.** It is a console process, such as a container that runs `php artisan schedule:work` and runs `php artisan migrate --database=pgsql_owner --force` on deploy. The migrations run as the owner role, because the app role cannot create tables. Run one of them; two do no harm, because `cms:partitions:maintain` holds an advisory lock in Postgres for a whole run, so runs never overlap.
- **Only the maintenance process schedules partition maintenance.** The core adds `cms:partitions:maintain` to the schedule only in a process whose configuration has the owner connection, so a scheduler in the web or queue process schedules nothing of the kernel's. Without a maintenance process no new partitions are created: `partitions.runway` fails, in every process, once a partitioned table has partitions for less than `cbox-cms.doctor.partition_runway_days` ahead, and a write past the last partition fails with `partition_missing`.

### Postgres messages in English

The kernel recognises some Postgres errors by their text, because the SQLSTATE does not tell them apart, so the messages must be English (PRD 4.2). Setting that up is the operator's job, done once for the database server and its roles; the kernel never changes a role or a server setting itself. `postgres.lc_messages`, which blocks, checks that a new session of the app role and of the owner role gets `lc_messages` `C`, `POSIX`, `C.<charset>` or `en_*`, and that the PHP process's `LC_MESSAGES`, which libpq's own messages follow, is English too. It reads the owner role's setting from the catalog as the app role, so it runs in the web and queue processes without the owner's credentials. As a superuser, run:

- `ALTER SYSTEM SET lc_messages = 'C'` and then `SELECT pg_reload_conf()`, so the errors from before a login are English as well;
- `ALTER ROLE <app role> SET lc_messages = 'C'` and `ALTER ROLE <owner role> SET lc_messages = 'C'`, so a session of either role is English whatever the server's default.

A value set with `ALTER ROLE ... IN DATABASE` wins over both, so reset it there. Start PHP with `LC_ALL` and `LC_MESSAGES` unset, `C` or `en_*`. When the check fails, its fix names the roles and the commands for this installation.

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

## Skips and crashes

A check never returns a skip. Only the doctor skips a check: when a check it requires did not pass, the doctor does not run it and reports it with the status skip and a cause that names the requirement, such as `postgres.reachable did not pass.`. A skip is not a failure and does not change the exit code; the failure of the requirement already does. A blocking check requires only blocking checks, so a skipped blocking check always comes with a blocking failure, and the kernel never starts without it.

The doctor always gives a complete report and never exits ok for a check that did not look. A check that breaks its contract fails as a `Violation` with the code `doctor_check_crashed`, and its cause says how it broke the contract. The failure keeps the check's blocking: a crashed blocking check makes the doctor exit 78, a crashed check that does not block 79. That covers a `run()` that throws, a result for another id or with another blocking than the check's own, and a skip returned from `run()`. The fix says it is a bug in the check.

## Exit codes: DoctorExitCode

The exit codes of `cms:doctor` are defined in one place, the enum `Cbox\Cms\Contracts\Doctor\DoctorExitCode`. `status()` gives the name the JSON document uses.

| Case | Exit code | `status()` | When |
|---|---|---|---|
| `Ok` | 0 | `ok` | every check passed or was skipped |
| `Unavailable` | 75, `EX_TEMPFAIL` of sysexits.h | `unavailable` | at least one blocking check failed as `Unavailable`, and no blocking check as `Violation` |
| `Violation` | 78, `EX_CONFIG` of sysexits.h | `violation` | at least one blocking check failed as `Violation` |
| `NotReady` | 79 | `not_ready` | no blocking check failed, and at least one check that does not block failed, as either kind |

When a blocking check fails, the blocking failures alone decide the code, and a violation wins over an unavailable dependency, because waiting does not fix it. The failures of checks that do not block count only when no blocking check fails: then the doctor exits 79. The document lists every failure, blocking or not. `DoctorExitCode::for($results)` adds up a list of `CheckResult`s this way. M1 folds these codes into the error catalog.

A readiness probe or a deploy guard can rely on the exit code alone: the kernel may start at 0 and 79, which `allowsStart()` says, and it is ready only at 0. 79 lies just above the range of sysexits.h, 64 to 78, so it has no other meaning there, and it cannot be mistaken for 1, a general error, or 2, wrong usage.

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
// FakeDoctorCheck standing in for real checks: the blocking failures decide the code, a violation
// wins over an unavailable dependency, and a failure of a check that does not block the kernel
// from starting gives 79 only when no blocking check fails.

it('exits ok when every check passes', function (): void {
    $results = [
        FakeDoctorCheck::passing(new CheckId('example.first'))->run(),
        FakeDoctorCheck::passing(new CheckId('example.second'), blocking: false)->run(),
    ];

    expect(DoctorExitCode::for($results))->toBe(DoctorExitCode::Ok)
        ->and(DoctorExitCode::Ok->value)->toBe(0)
        ->and(DoctorExitCode::Ok->status())->toBe('ok')
        ->and(DoctorExitCode::Ok->allowsStart())->toBeTrue();
});

it('exits unavailable when a blocking check cannot reach a dependency, whatever fails that does not block', function (): void {
    $results = [
        FakeDoctorCheck::failing(new CheckId('example.database'), FailureKind::Unavailable)->run(),
        FakeDoctorCheck::failing(new CheckId('example.workers'), FailureKind::Violation, blocking: false)->run(),
    ];

    expect(DoctorExitCode::for($results))->toBe(DoctorExitCode::Unavailable)
        ->and(DoctorExitCode::Unavailable->value)->toBe(75)
        ->and(DoctorExitCode::Unavailable->status())->toBe('unavailable')
        ->and(DoctorExitCode::Unavailable->allowsStart())->toBeFalse();
});

it('exits violation when a blocking check is violated, whatever else is unavailable', function (): void {
    $results = [
        FakeDoctorCheck::failing(new CheckId('example.database'), FailureKind::Unavailable)->run(),
        FakeDoctorCheck::failing(new CheckId('example.setting'), FailureKind::Violation)->run(),
        FakeDoctorCheck::failing(new CheckId('example.cache'), FailureKind::Unavailable, blocking: false)->run(),
    ];

    expect(DoctorExitCode::for($results))->toBe(DoctorExitCode::Violation)
        ->and(DoctorExitCode::Violation->value)->toBe(78)
        ->and(DoctorExitCode::Violation->status())->toBe('violation')
        ->and(DoctorExitCode::Violation->allowsStart())->toBeFalse();
});

it('exits not ready when only checks that do not block fail, so the kernel may start but is not ready', function (): void {
    $results = [
        FakeDoctorCheck::passing(new CheckId('example.first'))->run(),
        FakeDoctorCheck::failing(new CheckId('example.workers'), FailureKind::Violation, blocking: false)->run(),
        FakeDoctorCheck::failing(new CheckId('example.cache'), FailureKind::Unavailable, blocking: false)->run(),
    ];

    expect(DoctorExitCode::for($results))->toBe(DoctorExitCode::NotReady)
        ->and(DoctorExitCode::NotReady->value)->toBe(79)
        ->and(DoctorExitCode::NotReady->status())->toBe('not_ready')
        ->and(DoctorExitCode::NotReady->allowsStart())->toBeTrue();
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
| `status` | `ok`, `violation`, `unavailable` or `not_ready` | the name of the exit code, `DoctorExitCode::status()` |
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
- The status `violation` has an exit code of at least 1, and at least one blocking check failed as `violation`.
- The status `unavailable` has an exit code of at least 1, at least one blocking check failed as `unavailable`, and no blocking check failed as `violation`.
- The status `not_ready` has an exit code of at least 1, at least one check that does not block failed, and no blocking check failed.

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
