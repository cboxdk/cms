<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Ci;

use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Cbox\Cms\Tests\Support\Tooling\ScratchRepository;
use Symfony\Component\Process\Process;

/*
 * bin/ci, the single CI entry script, which leaves mutation testing out unless CMS_CI_MUTATION=1
 * (deferred until after v1, Sylvester, 2 October 2026), and docker/ci-entry.sh, which runs it on a clean git
 * archive of HEAD in compose.ci.yaml, on the merge base of CMS_CI_BASE_REF, or on the base it
 * derives when that is unset, empty or 40 zeros. bin/ci runs here with fake composer, npm, php, node, psql
 * and pg_isready on the PATH, which record how they were called; ci-entry.sh runs on a scratch
 * repository.
 */

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

/**
 * A directory of fake programs that append their name, arguments and selected variables to
 * calls.log. `composer check` prints a summary and exits with $FAKE_CHECK_EXIT.
 */
function fakeTools(string $scratch): string
{
    $bin = $scratch.'/bin';
    $record = <<<'SH'
        #!/usr/bin/env bash
        printf '%s %s | PGHOST=%s PGPORT=%s PGPASSWORD=%s\n' "$(basename "$0")" "$*" "${CMS_INIT_PGHOST:-}" "${PGPORT:-}" "${PGPASSWORD:-}" >> "$FAKE_CALLS"
        SH;

    foreach (['npm', 'php', 'node', 'psql', 'pg_isready'] as $tool) {
        ScratchDirectory::write("{$bin}/{$tool}", $record."\necho 1.0.0\n");
    }

    ScratchDirectory::write("{$bin}/composer", $record."\n".<<<'SH_WRAP'
    if [[ "$1" == check ]]; then
        printf 'Gate 1  Pint and Prettier\n\nSummary\n  Gate 1   pass      Pint and Prettier\n'
        for argument in "$@"; do
            [[ "$argument" == --shard=* ]] && exit "${FAKE_SHARD_EXIT:-${FAKE_CHECK_EXIT:-0}}"
        done
        exit "${FAKE_CHECK_EXIT:-0}"
    fi
    if [[ "$1" == mutation:plan ]]; then
        for argument in "$@"; do
            if [[ "$argument" == --github-output=* ]]; then
                printf 'count=%s\nshards=[%s]\n' "${FAKE_SHARDS:-1}" "$(seq 1 "${FAKE_SHARDS:-1}" | paste -sd, -)" >> "${argument#--github-output=}"
            fi
        done
    fi
    if [[ "$1" == mutation:verdict ]]; then
        printf 'mutation:verdict: the fake verdict\nverdict: %s\n' "$([[ "${FAKE_VERDICT_EXIT:-0}" == 0 ]] && echo pass || echo fail)"
        exit "${FAKE_VERDICT_EXIT:-0}"
    fi
    SH_WRAP);

    foreach (glob($bin.'/*') ?: [] as $file) {
        chmod($file, 0o755);
    }

    return $bin;
}

/**
 * The variables ci.yml gives a job, which bin/ci reads. The suites run inside such a job on GitHub, and
 * a process inherits them, so every run of bin/ci here unsets them unless a test sets one.
 *
 * @return array<string, false>
 */
function ciJobVariables(): array
{
    return [
        'CMS_CI_PART' => false,
        'CMS_CI_MUTATION' => false,
        'CMS_CI_BASE_REF' => false,
        'CMS_CI_GATES_RESULT' => false,
        'CMS_CI_SHARDS_RESULT' => false,
        'CMS_CI_ARTIFACTS' => false,
        'CMS_CI_PROVISION_POSTGRES' => false,
        'CMS_CI_POSTGRES_SUPERUSER' => false,
        'CMS_CI_USER' => false,
        'GITHUB_OUTPUT' => false,
        'GITHUB_STEP_SUMMARY' => false,
    ];
}

/**
 * @param  array<string, string|false>  $env  false unsets the variable
 * @return array{Process, list<string>}
 */
function runBinCi(string $scratch, array $env = []): array
{
    $process = new Process([Phpstan::root().'/bin/ci'], $scratch, [
        ...ciJobVariables(),
        'PATH' => fakeTools($scratch).':/usr/bin:/bin',
        'FAKE_CALLS' => $scratch.'/calls.log',
        'CMS_CI_REPORT' => $scratch.'/build/check.json',
        'CMS_CI_PROVISION_POSTGRES' => false,
        'GITHUB_STEP_SUMMARY' => false,
        'CMS_CI_RUNNER' => 'the test runner',
        ...$env,
    ], null, 60);
    $process->run();

    $calls = is_file($scratch.'/calls.log') ? file($scratch.'/calls.log', FILE_IGNORE_NEW_LINES) : [];

    return [$process, array_map(static fn (string $line): string => explode(' | ', $line)[0], $calls ?: [])];
}

/**
 * The calls of a run other than the version probes of the environment section.
 *
 * @param  list<string>  $calls
 * @return list<string>
 */
function ciWork(array $calls): array
{
    return array_values(array_filter($calls, static fn (string $call): bool => ! str_contains($call, ' --version')));
}

it('installs the locked dependencies and by default runs only the gates of the PR profile, with nothing of mutation testing, which is deferred until after v1', function (string|false $mutation): void {
    $scratch = ScratchDirectory::make();
    [$process, $calls] = runBinCi($scratch, ['FAKE_SHARDS' => '2', 'CMS_CI_MUTATION' => $mutation, 'CMS_CI_BASE_REF' => 'abc123']);
    $output = $process->getOutput();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(ciWork($calls))->toBe([
            'composer install --no-interaction --no-progress --prefer-dist',
            'npm ci --no-audit --no-fund',
            "composer check -- --pr --report={$scratch}/build/check.json",
        ])
        ->and($output)->toContain('runner: the test runner', 'part: all', 'Summary')
        ->and($output)->toContain("mutation testing: not run, mutation testing deferred until after v1 (Sylvester, 2 October 2026); CMS_CI_MUTATION=1 runs it\n")
        ->and($output)->toMatch('/bin\/ci: gates: 0m \d\ds, pass\n/')
        ->and($output)->not->toContain('base of the change')
        ->and($output)->not->toContain('verdict')
        ->and($output)->not->toContain('shard')
        ->and($output)->not->toContain('plan:')
        ->and($output)->toContain('wall time on the test runner for all; the GUARDRAILS 10 budget is 15 minutes for each part')
        ->and((string) file_get_contents($scratch.'/build/check.log'))->toContain('Gate 1   pass')
        ->and($scratch.'/build/artifacts')->not->toBeDirectory();
})->with(['unset' => [false], 'empty' => [''], '0' => ['0']]);

it('installs the locked dependencies and with CMS_CI_MUTATION=1 runs every part with the PR profile, nothing else: the plan, the gates, each shard and the verdict', function (): void {
    $scratch = ScratchDirectory::make();
    [$process, $calls] = runBinCi($scratch, ['FAKE_SHARDS' => '2', 'CMS_CI_MUTATION' => '1']);
    $artifacts = $scratch.'/build/artifacts';

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(ciWork($calls))->toBe([
            'composer install --no-interaction --no-progress --prefer-dist',
            'npm ci --no-audit --no-fund',
            "composer mutation:plan -- --output={$artifacts}/mutation-plan/mutation-plan.json --github-output={$artifacts}/mutation-plan/plan.env",
            "composer check -- --pr --report={$scratch}/build/check.json --mutation --only=gates",
            "composer check -- --pr --report={$artifacts}/mutation-shard-1/check.json --mutation --shard=1/2 --mutation-report={$artifacts}/mutation-shard-1/mutation-shard.json",
            "composer check -- --pr --report={$artifacts}/mutation-shard-2/check.json --mutation --shard=2/2 --mutation-report={$artifacts}/mutation-shard-2/mutation-shard.json",
            "composer mutation:verdict -- --plan={$artifacts}/mutation-plan/mutation-plan.json --reports={$artifacts} --gates=success --shards=success",
        ])
        ->and($process->getOutput())->toContain('runner: the test runner', 'part: all', 'Summary', 'verdict: pass', "mutation testing: run, as CMS_CI_MUTATION=1 asks\n")
        ->and($process->getOutput())->toMatch('/bin\/ci: plan: 0m \d\ds, pass\n/')
        ->and($process->getOutput())->toMatch('/bin\/ci: gates: 0m \d\ds, pass\n/')
        ->and($process->getOutput())->toMatch('/bin\/ci: shard:1\/2: 0m \d\ds, pass\n/')
        ->and($process->getOutput())->toMatch('/bin\/ci: shard:2\/2: 0m \d\ds, pass\n/')
        ->and($process->getOutput())->toMatch('/bin\/ci: verdict: 0m \d\ds, pass\n/')
        ->and($process->getOutput())->toContain('wall time on the test runner for all; the GUARDRAILS 10 budget is 15 minutes for each part')
        ->and((string) file_get_contents($scratch.'/build/check.log'))->toContain('Gate 1   pass')
        ->and((string) file_get_contents($artifacts.'/mutation-shard-2/check.log'))->toContain('Gate 1   pass')
        ->and((string) file_get_contents($artifacts.'/verdict.log'))->toContain('verdict: pass');
});

it('runs every part when the process that runs the suite is itself a job of ci.yml', function (): void {
    $scratch = ScratchDirectory::make();
    $inherited = ['CMS_CI_PART' => 'shard:1/1', 'CMS_CI_MUTATION' => '1', 'CMS_CI_GATES_RESULT' => 'failure', 'CMS_CI_SHARDS_RESULT' => 'failure'];

    foreach ($inherited as $name => $value) {
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
    }

    try {
        [$process, $calls] = runBinCi($scratch, ['FAKE_SHARDS' => '1', 'CMS_CI_MUTATION' => '1']);
        [$default, $defaultCalls] = runBinCi(ScratchDirectory::make(), ['FAKE_SHARDS' => '1']);
    } finally {
        foreach (array_keys($inherited) as $name) {
            putenv($name);
            unset($_ENV[$name]);
        }
    }

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toContain('part: all', 'verdict: pass')
        ->and(array_last(ciWork($calls)))->toEndWith('--gates=success --shards=success')
        ->and($default->getExitCode())->toBe(0, $default->getErrorOutput())
        ->and($default->getOutput())->toContain('part: all', 'mutation testing: not run')
        ->and(array_last(ciWork($defaultCalls)))->toEndWith('/build/check.json');
});

it('hands the verdict a failed shard and a failed gate as failure, and exits 1', function (string $variable, string $gates, string $shards): void {
    $scratch = ScratchDirectory::make();
    [$process, $calls] = runBinCi($scratch, [$variable => '1', 'FAKE_SHARDS' => '2', 'FAKE_VERDICT_EXIT' => '1', 'CMS_CI_MUTATION' => '1']);

    expect($process->getExitCode())->toBe(1)
        ->and(array_last(ciWork($calls)))->toEndWith("--gates={$gates} --shards={$shards}")
        ->and($process->getOutput())->toContain('verdict: fail');
})->with([
    'a shard' => ['FAKE_SHARD_EXIT', 'success', 'failure'],
    'the gates and the shards' => ['FAKE_CHECK_EXIT', 'failure', 'failure'],
]);

it('runs one part of ci.yml\'s jobs when CMS_CI_PART names it', function (string $part, array $expected, array $env): void {
    $scratch = ScratchDirectory::make();
    $variables = ['CMS_CI_PART' => $part];

    foreach ($env as $name => $value) {
        if (is_string($name) && is_string($value)) {
            $variables[$name] = str_replace('{scratch}', $scratch, $value);
        }
    }

    [$process, $calls] = runBinCi($scratch, $variables);
    $expected = array_map(static fn (string $call): string => str_replace('{scratch}', $scratch, $call), array_values(array_filter($expected, is_string(...))));

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(ciWork($calls))->toBe($expected)
        ->and($process->getOutput())->toContain("part: {$part}");
})->with([
    'the plan, without npm' => ['plan', [
        'composer install --no-interaction --no-progress --prefer-dist',
        'composer mutation:plan -- --output={scratch}/build/mutation-plan.json --github-output={scratch}/build/plan.env',
    ], ['CMS_CI_MUTATION' => '1']],
    'the gates, without mutation testing' => ['gates', [
        'composer install --no-interaction --no-progress --prefer-dist',
        'npm ci --no-audit --no-fund',
        'composer check -- --pr --report={scratch}/build/check.json',
    ], []],
    'the gates, with the Mutation suite' => ['gates', [
        'composer install --no-interaction --no-progress --prefer-dist',
        'npm ci --no-audit --no-fund',
        'composer check -- --pr --report={scratch}/build/check.json --mutation --only=gates',
    ], ['CMS_CI_MUTATION' => '1']],
    'a shard' => ['shard:3/12', [
        'composer install --no-interaction --no-progress --prefer-dist',
        'npm ci --no-audit --no-fund',
        'composer check -- --pr --report={scratch}/build/check.json --mutation --shard=3/12 --mutation-report={scratch}/build/mutation-shard.json',
    ], ['CMS_CI_MUTATION' => '1']],
    'the verdict, without npm' => ['verdict', [
        'composer install --no-interaction --no-progress --prefer-dist',
        'composer mutation:verdict -- --plan={scratch}/downloaded/mutation-plan/mutation-plan.json --reports={scratch}/downloaded --gates=success --shards=cancelled',
    ], ['CMS_CI_GATES_RESULT' => 'success', 'CMS_CI_SHARDS_RESULT' => 'cancelled', 'CMS_CI_ARTIFACTS' => '{scratch}/downloaded', 'CMS_CI_MUTATION' => '1']],
]);

it('refuses a part of mutation testing without CMS_CI_MUTATION=1, before installing anything, because it is deferred until after v1', function (string $part, string|false $mutation): void {
    $scratch = ScratchDirectory::make();
    [$process, $calls] = runBinCi($scratch, ['CMS_CI_PART' => $part, 'CMS_CI_MUTATION' => $mutation]);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain("CMS_CI_PART={$part} is a part of mutation testing, which runs only with CMS_CI_MUTATION=1: mutation testing deferred until after v1 (Sylvester, 2 October 2026)")
        ->and(ciWork($calls))->toBe([]);
})->with(['plan', 'shard:1/2', 'verdict'])->with(['unset' => [false], '0' => ['0']]);

it('refuses a CMS_CI_MUTATION other than 1, 0 or empty before installing anything', function (string $value): void {
    $scratch = ScratchDirectory::make();
    [$process, $calls] = runBinCi($scratch, ['CMS_CI_MUTATION' => $value]);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain("CMS_CI_MUTATION={$value} is neither 1")
        ->and(ciWork($calls))->toBe([]);
})->with(['true', 'yes', '2']);

it('writes the plan\'s count and matrix to GitHub\'s step outputs in the part plan', function (): void {
    $scratch = ScratchDirectory::make();
    ScratchDirectory::write($scratch.'/github-output', "earlier=1\n");
    [$process] = runBinCi($scratch, ['CMS_CI_PART' => 'plan', 'FAKE_SHARDS' => '3', 'GITHUB_OUTPUT' => $scratch.'/github-output', 'CMS_CI_MUTATION' => '1']);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and((string) file_get_contents($scratch.'/github-output'))->toBe("earlier=1\ncount=3\nshards=[1,2,3]\n");
});

it('refuses a part it does not know, and a shard outside its count, before installing anything', function (string $part): void {
    $scratch = ScratchDirectory::make();
    [$process, $calls] = runBinCi($scratch, ['CMS_CI_PART' => $part]);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain("CMS_CI_PART={$part}")
        ->and(ciWork($calls))->toBe([]);
})->with(['mutation', 'shard:3/2', 'shard:0/2', 'shard:1', 'shard:a/b']);

it('runs the gates without a base of the change, and says the base is derived, when CMS_CI_BASE_REF is unset, empty or 40 zeros with CMS_CI_MUTATION=1', function (string|false $ref, string $shown): void {
    $scratch = ScratchDirectory::make();
    [$process, $calls] = runBinCi($scratch, ['CMS_CI_BASE_REF' => $ref, 'CMS_CI_MUTATION' => '1']);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toContain("base of the change: CMS_CI_BASE_REF={$shown} names none, so it is derived from the checkout: HEAD~1 on main, the merge base with origin/main on another branch, every file for a first commit")
        ->and($calls)->toContain("composer check -- --pr --report={$scratch}/build/check.json --mutation --only=gates");
})->with([
    'unset' => [false, '(not set)'],
    'empty' => ['', ''],
    '40 zeros' => [str_repeat('0', 40), str_repeat('0', 40)],
]);

it('names the base of the change it was given with CMS_CI_MUTATION=1', function (): void {
    [$process] = runBinCi(ScratchDirectory::make(), ['CMS_CI_BASE_REF' => 'abc123', 'CMS_CI_MUTATION' => '1']);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain("base of the change: CMS_CI_BASE_REF=abc123\n")
        ->and($process->getOutput())->not->toContain('derived');
});

it('exits 1 when a gate fails, after writing the summary for GitHub without a mutation verdict, as mutation testing is deferred', function (): void {
    $scratch = ScratchDirectory::make();
    [$process] = runBinCi($scratch, ['FAKE_CHECK_EXIT' => '1', 'GITHUB_STEP_SUMMARY' => $scratch.'/summary.md']);
    $summary = (string) file_get_contents($scratch.'/summary.md');

    expect($process->getExitCode())->toBe(1)
        ->and($summary)->toContain("### PR profile (GUARDRAILS 10): all\n\nmutation testing: not run, mutation testing deferred until after v1 (Sylvester, 2 October 2026); CMS_CI_MUTATION=1 runs it\n\n```text\nSummary\n  Gate 1   pass      Pint and Prettier\ngates: ", 'the GUARDRAILS 10 budget is 15 minutes')
        ->and($summary)->toMatch('/gates: 0m \d\ds, fail\n/')
        ->and($summary)->not->toContain('verdict')
        ->and($summary)->not->toContain('Gate 1  Pint and Prettier');
});

it('exits 1 when a gate fails with CMS_CI_MUTATION=1, after writing the summary for GitHub with the verdict', function (): void {
    $scratch = ScratchDirectory::make();
    [$process] = runBinCi($scratch, ['FAKE_CHECK_EXIT' => '1', 'GITHUB_STEP_SUMMARY' => $scratch.'/summary.md', 'CMS_CI_MUTATION' => '1']);
    $summary = (string) file_get_contents($scratch.'/summary.md');

    expect($process->getExitCode())->toBe(1)
        ->and($summary)->toContain("### PR profile (GUARDRAILS 10): all\n\nmutation testing: run, as CMS_CI_MUTATION=1 asks\n\n```text\nSummary\n  Gate 1   pass      Pint and Prettier\nmutation:verdict: the fake verdict\nverdict: pass\n", 'the GUARDRAILS 10 budget is 15 minutes')
        ->and($summary)->toMatch('/gates: 0m \d\ds, fail\n/')
        ->and($summary)->not->toContain('Gate 1  Pint and Prettier');
});

it('stops before the gates when an install fails', function (): void {
    $scratch = ScratchDirectory::make();
    fakeTools($scratch);
    ScratchDirectory::write($scratch.'/bin/npm', "#!/usr/bin/env bash\n[[ \"\$1\" == ci ]] && exit 7\necho 10.0.0\n");
    chmod($scratch.'/bin/npm', 0o755);

    $process = new Process([Phpstan::root().'/bin/ci'], $scratch, [
        ...ciJobVariables(),
        'PATH' => $scratch.'/bin:/usr/bin:/bin',
        'FAKE_CALLS' => $scratch.'/calls.log',
        'CMS_CI_REPORT' => $scratch.'/build/check.json',
    ], null, 60);
    $process->run();

    expect($process->getExitCode())->toBe(7)
        ->and((string) file_get_contents($scratch.'/calls.log'))->not->toContain('composer check');
});

it('creates the roles and databases over TCP as the superuser before installing, when asked to', function (): void {
    $scratch = ScratchDirectory::make();
    [$process] = runBinCi($scratch, [
        'CMS_CI_PROVISION_POSTGRES' => '1',
        'DB_HOST' => 'postgres',
        'DB_PORT' => '5432',
        'CMS_CI_POSTGRES_SUPERUSER' => 'super',
        'CMS_CI_POSTGRES_PASSWORD' => 'secret',
        'CMS_DATABASES' => 'cms cms_test',
        'CMS_SCHEMA' => 'cms',
        'CMS_OWNER_ROLE' => 'cms_owner',
        'CMS_OWNER_PASSWORD' => 'cms_owner',
        'CMS_APP_ROLE' => 'cms_app',
        'CMS_APP_PASSWORD' => 'cms_app',
        'CMS_APP_TRANSACTION_TIMEOUT' => '5s',
    ]);
    $calls = array_values(array_filter(
        file($scratch.'/calls.log', FILE_IGNORE_NEW_LINES) ?: [],
        static fn (string $call): bool => str_starts_with($call, 'psql ') || str_starts_with($call, 'pg_isready ') || str_starts_with($call, 'composer install'),
    ));

    expect($process->getExitCode())->toBe(0)
        ->and($calls)->toHaveCount(5)
        ->and($calls[0])->toStartWith('pg_isready --quiet --host=postgres --port=5432 |')
        ->and($calls[1])->toContain('--username=super', '--file=docker/postgres/sql/roles.sql', '| PGHOST=postgres PGPORT=5432 PGPASSWORD=secret')
        ->and($calls[2])->toContain('--set=db=cms --file=docker/postgres/sql/database.sql', 'PGHOST=postgres')
        ->and($calls[3])->toContain('--set=db=cms_test --file=docker/postgres/sql/database.sql', 'PGHOST=postgres')
        ->and($calls[4])->toStartWith('composer install');
});

it('runs its command on a clean archive of HEAD: committed files only, in a repository with one clean commit', function (): void {
    $source = ScratchDirectory::make();
    $work = ScratchDirectory::make().'/work';
    $git = static function (string ...$arguments) use ($source): string {
        $process = new Process(['git', '-c', 'user.name=Ci Test', '-c', 'user.email=ci@example.test', '-c', 'commit.gpgsign=false', '-c', 'core.hooksPath=/dev/null', ...array_values($arguments)], $source);
        $process->mustRun();

        return trim($process->getOutput());
    };

    $git('init', '--quiet');
    ScratchDirectory::write($source.'/committed.php', "<?php\n// committed\n");
    ScratchDirectory::write($source.'/.gitignore', "ignored.txt\n");
    ScratchDirectory::write($source.'/ignored.txt', "tracked although ignored\n");
    $git('add', 'committed.php', '.gitignore');
    $git('add', '--force', 'ignored.txt');
    $git('commit', '--quiet', '--message=fixture');
    $head = $git('rev-parse', 'HEAD');
    ScratchDirectory::write($source.'/committed.php', "<?php\n// changed, not committed\n");
    ScratchDirectory::write($source.'/untracked.php', "<?php\n\$x=1 ;\n");

    $process = new Process([
        Phpstan::root().'/docker/ci-entry.sh',
        'bash', '-c', 'ls -A | sort | tr "\n" " "; echo; git status --porcelain --ignored; git rev-list --count HEAD; git log --format=%s; cat committed.php',
    ], null, ['CMS_CI_SOURCE' => $source.'/.git', 'CMS_CI_WORK' => $work, 'CMS_CI_BASE_REF' => false], null, 60);
    $process->run();

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toBe(implode("\n", [
            "ci-entry: HEAD {$head} archived to {$work}",
            '.git .gitignore committed.php ignored.txt ',
            '1',
            "HEAD {$head} of the mounted repository",
            '<?php',
            '// committed',
            '',
        ]))
        ->and($git('status', '--porcelain'))->toBe("M committed.php\n?? untracked.php");
});

it('runs HEAD\'s docker/ci-setup.sh and then HEAD\'s bin/ci in the archive when it is given no command, as ci.yml runs them', function (): void {
    $source = ScratchDirectory::make();
    $work = ScratchDirectory::make().'/work';
    $git = static function (string ...$arguments) use ($source): void {
        new Process(['git', '-c', 'user.name=Ci Test', '-c', 'user.email=ci@example.test', '-c', 'commit.gpgsign=false', '-c', 'core.hooksPath=/dev/null', ...array_values($arguments)], $source)->mustRun();
    };

    $git('init', '--quiet');
    ScratchDirectory::write($source.'/docker/ci-setup.sh', "#!/usr/bin/env bash\necho \"setup in \$PWD\"\n");
    ScratchDirectory::write($source.'/bin/ci', "#!/usr/bin/env bash\necho \"bin/ci in \$PWD\"\n");
    chmod($source.'/docker/ci-setup.sh', 0o755);
    chmod($source.'/bin/ci', 0o755);
    $git('add', 'docker/ci-setup.sh', 'bin/ci');
    $git('commit', '--quiet', '--message=fixture');
    // A working-tree change that must not run.
    ScratchDirectory::write($source.'/docker/ci-setup.sh', "#!/usr/bin/env bash\necho 'setup from the working tree'\n");

    $process = new Process([Phpstan::root().'/docker/ci-entry.sh'], null, ['CMS_CI_SOURCE' => $source.'/.git', 'CMS_CI_WORK' => $work, 'CMS_CI_BASE_REF' => false], null, 60);
    $process->run();
    $lines = explode("\n", trim($process->getOutput()));

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(array_slice($lines, 1))->toBe(["setup in {$work}", "bin/ci in {$work}"]);
});

it('stops before bin/ci when the setup fails', function (): void {
    $source = ScratchDirectory::make();
    $work = ScratchDirectory::make().'/work';
    $git = static function (string ...$arguments) use ($source): void {
        new Process(['git', '-c', 'user.name=Ci Test', '-c', 'user.email=ci@example.test', '-c', 'commit.gpgsign=false', '-c', 'core.hooksPath=/dev/null', ...array_values($arguments)], $source)->mustRun();
    };

    $git('init', '--quiet');
    ScratchDirectory::write($source.'/docker/ci-setup.sh', "#!/usr/bin/env bash\necho 'no Chromium' >&2\nexit 1\n");
    ScratchDirectory::write($source.'/bin/ci', "#!/usr/bin/env bash\necho 'bin/ci ran'\n");
    chmod($source.'/docker/ci-setup.sh', 0o755);
    chmod($source.'/bin/ci', 0o755);
    $git('add', 'docker/ci-setup.sh', 'bin/ci');
    $git('commit', '--quiet', '--message=fixture');

    $process = new Process([Phpstan::root().'/docker/ci-entry.sh'], null, ['CMS_CI_SOURCE' => $source.'/.git', 'CMS_CI_WORK' => $work, 'CMS_CI_BASE_REF' => false], null, 60);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->not->toContain('bin/ci ran')
        ->and($process->getErrorOutput())->toContain('no Chromium');
});

it('builds the archive as the merge base\'s tree and then HEAD\'s, and runs with CMS_CI_BASE_REF=HEAD~1', function (): void {
    $source = ScratchRepository::make();
    $work = ScratchDirectory::make().'/work';
    $source->write('packages/demo/src/Kept.php', "<?php\n// base\n")->write('packages/demo/src/Removed.php', "<?php\n")->write('.gitignore', "ignored.txt\n");
    $base = $source->commit('base');
    $source->git('checkout', '--quiet', '-b', 'feature');
    $source->write('packages/demo/src/Kept.php', "<?php\n// feature\n")->write('packages/demo/src/Added.php', "<?php\n")->delete('packages/demo/src/Removed.php');
    $head = $source->commit('feature');
    // main moves on after the fork; its change is not the feature's.
    $source->git('checkout', '--quiet', 'main');
    $source->write('packages/demo/src/OnMain.php', "<?php\n")->commit('main moves on');
    $source->git('checkout', '--quiet', 'feature');

    $process = new Process([
        Phpstan::root().'/docker/ci-entry.sh',
        'bash', '-c', 'echo "ref=$CMS_CI_BASE_REF"; git rev-list --count HEAD; git log --format=%s; git diff --no-renames --name-status HEAD~1 HEAD; git status --porcelain --ignored; cat packages/demo/src/Kept.php',
    ], null, ['CMS_CI_SOURCE' => $source->root.'/.git', 'CMS_CI_WORK' => $work, 'CMS_CI_BASE_REF' => 'main'], null, 60);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toBe(implode("\n", [
            "ci-entry: HEAD {$head} archived to {$work} on its merge base {$base}; CMS_CI_BASE_REF=HEAD~1",
            'ref=HEAD~1',
            '2',
            "HEAD {$head} of the mounted repository",
            "the merge base {$base} of CMS_CI_BASE_REF=main and HEAD",
            "A\tpackages/demo/src/Added.php",
            "M\tpackages/demo/src/Kept.php",
            "D\tpackages/demo/src/Removed.php",
            '<?php',
            '// feature',
            '',
        ]));
});

it('keeps HEAD\'s tree as the second commit when HEAD is the merge base, so nothing changed', function (): void {
    $source = ScratchRepository::make();
    $work = ScratchDirectory::make().'/work';
    $head = $source->write('packages/demo/src/Kept.php', "<?php\n")->commit('base');

    $process = new Process([Phpstan::root().'/docker/ci-entry.sh', 'bash', '-c', 'git rev-list --count HEAD; git diff --name-only HEAD~1 HEAD | wc -l'],
        null, ['CMS_CI_SOURCE' => $source->root.'/.git', 'CMS_CI_WORK' => $work, 'CMS_CI_BASE_REF' => $head], null, 60);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(array_map(trim(...), explode("\n", trim($process->getOutput()))))->toBe([
            "ci-entry: HEAD {$head} archived to {$work} on its merge base {$head}; CMS_CI_BASE_REF=HEAD~1",
            '2',
            '0',
        ]);
});

it('stops before anything runs when CMS_CI_BASE_REF names no commit or has no merge base with HEAD', function (string $ref, string $message): void {
    $source = ScratchRepository::make();
    $work = ScratchDirectory::make().'/work';
    $source->write('packages/demo/src/Kept.php', "<?php\n")->commit('base');
    $source->git('checkout', '--quiet', '--orphan', 'unrelated');
    $source->write('packages/demo/src/Other.php', "<?php\n")->commit('unrelated');
    $source->git('checkout', '--quiet', 'main');

    $process = new Process([Phpstan::root().'/docker/ci-entry.sh', 'bash', '-c', 'echo ran'],
        null, ['CMS_CI_SOURCE' => $source->root.'/.git', 'CMS_CI_WORK' => $work, 'CMS_CI_BASE_REF' => $ref], null, 60);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->not->toContain('ran')
        ->and($process->getErrorOutput())->toContain($message)
        ->and($work)->not->toBeDirectory();
})->with([
    'an unknown ref' => ['origin/no-such-branch', 'ci-entry: CMS_CI_BASE_REF=origin/no-such-branch names no commit in the mounted repository.'],
    'unrelated history' => ['unrelated', 'ci-entry: CMS_CI_BASE_REF=unrelated has no merge base with HEAD'],
]);

/**
 * A mounted repository whose main has two commits: the base, and one that changes a class. The
 * branch feature forks from the second commit and changes another class; main then moves on.
 *
 * @return array{ScratchRepository, array<string, string>}
 */
function derivedBaseSource(): array
{
    $source = ScratchRepository::make();
    $source->write('packages/demo/src/Kept.php', "<?php\n\nnamespace Acme\\Demo;\n\nfinal class Kept {}\n")->write('packages/demo/src/Other.php', "<?php\n\nnamespace Acme\\Demo;\n\nfinal class Other {}\n");
    $first = $source->commit('base');
    $source->write('packages/demo/src/Kept.php', "<?php\n\nnamespace Acme\\Demo;\n\nfinal class Kept { public int \$n = 1; }\n");
    $second = $source->commit('main changes Kept');
    $source->git('checkout', '--quiet', '-b', 'feature');
    $source->write('packages/demo/src/Other.php', "<?php\n\nnamespace Acme\\Demo;\n\nfinal class Other { public int \$n = 1; }\n");
    $feature = $source->commit('feature changes Other');
    $source->git('checkout', '--quiet', 'main');
    $source->write('packages/demo/src/OnMain.php', "<?php\n\nnamespace Acme\\Demo;\n\nfinal class OnMain {}\n");
    $main = $source->commit('main moves on');

    return [$source, ['first' => $first, 'second' => $second, 'feature' => $feature, 'main' => $main]];
}

/**
 * Runs docker/ci-entry.sh on the mounted repository with a command that prints CMS_CI_BASE_REF,
 * the commits of the new repository, and the classes mutation on changed files mutates there, as
 * bin/ci's composer check finds them with this checkout's GitMutationScope.
 *
 * @return array{Process, string}
 */
function runEntryWithScope(ScratchRepository $source, string|false $baseRef): array
{
    $work = ScratchDirectory::make().'/work';
    $script = ScratchDirectory::make().'/scope.php';
    ScratchDirectory::write($script, <<<'PHP'
        <?php

        declare(strict_types=1);

        require $argv[1].'/vendor/autoload.php';

        $ref = getenv('CMS_CI_BASE_REF');
        $scope = Cbox\Cms\Tooling\Mutation\Boundary\GitMutationScope::resolve((string) getcwd(), $ref === false ? null : $ref);

        echo 'failure='.($scope->failure ?? '')."\n";

        foreach ($scope->sources as $source) {
            echo $source->name."\n";
        }
        PHP);

    $process = new Process([
        Phpstan::root().'/docker/ci-entry.sh',
        'bash', '-c', 'echo "ref=${CMS_CI_BASE_REF-(not set)}"; git rev-list --count HEAD; "$0" "$1" "$2"',
        PHP_BINARY, $script, Phpstan::root(),
    ], null, ['CMS_CI_SOURCE' => $source->root.'/.git', 'CMS_CI_WORK' => $work, 'CMS_CI_BASE_REF' => $baseRef], null, 60);
    $process->run();

    return [$process, $work];
}

it('derives the base in the mounted repository when CMS_CI_BASE_REF is unset, empty or 40 zeros: HEAD~1 on main, and bin/ci sees the classes of the last commit', function (string|false $ref, string $reason): void {
    [$source, $commits] = derivedBaseSource();

    [$process, $work] = runEntryWithScope($source, $ref);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toBe(implode("\n", [
            "ci-entry: HEAD {$commits['main']} archived to {$work} on its base {$commits['second']}, HEAD~1 of main, as {$reason} and HEAD is main; CMS_CI_BASE_REF=HEAD~1",
            'ref=HEAD~1',
            '2',
            'failure=',
            'Acme\Demo\OnMain',
            '',
        ]));
})->with([
    'unset' => [false, 'CMS_CI_BASE_REF is not set'],
    'empty' => ['', 'CMS_CI_BASE_REF is empty'],
    '40 zeros' => [str_repeat('0', 40), 'CMS_CI_BASE_REF is 40 zeros, the commit before a push that created the branch'],
]);

it('derives the merge base with origin/main, or main without it, on a branch when CMS_CI_BASE_REF is unset, and bin/ci sees the branch\'s classes', function (bool $withOrigin): void {
    [$source, $commits] = derivedBaseSource();

    if ($withOrigin) {
        // origin/main is behind the local main: its merge base with the feature is the first commit.
        $source->git('update-ref', 'refs/remotes/origin/main', $commits['first']);
    }

    $source->git('checkout', '--quiet', 'feature');
    $mainline = $withOrigin ? 'origin/main' : 'main';
    $base = $withOrigin ? $commits['first'] : $commits['second'];

    [$process, $work] = runEntryWithScope($source, false);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toBe(implode("\n", [
            "ci-entry: HEAD {$commits['feature']} archived to {$work} on its base {$base}, the merge base of {$mainline} and HEAD, as CMS_CI_BASE_REF is not set and HEAD is not main; CMS_CI_BASE_REF=HEAD~1",
            'ref=HEAD~1',
            '2',
            'failure=',
            ...($withOrigin ? ['Acme\Demo\Kept', 'Acme\Demo\Other'] : ['Acme\Demo\Other']),
            '',
        ]));
})->with(['origin/main' => true, 'main, without origin/main' => false]);

it('builds one commit when HEAD is the only commit of the mounted repository, and bin/ci counts every file as changed', function (): void {
    $source = ScratchRepository::make();
    $head = $source->write('packages/demo/src/Kept.php', "<?php\n\nnamespace Acme\\Demo;\n\nfinal class Kept {}\n")->write('packages/demo/src/Other.php', "<?php\n\nnamespace Acme\\Demo;\n\nfinal class Other {}\n")->commit('only');

    [$process, $work] = runEntryWithScope($source, str_repeat('0', 40));

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toBe(implode("\n", [
            "ci-entry: HEAD {$head} archived to {$work}",
            'ref=(not set)',
            '1',
            'failure=',
            'Acme\Demo\Kept',
            'Acme\Demo\Other',
            '',
        ]));
});

it('stops before anything runs when CMS_CI_BASE_REF is unset and the base cannot be derived', function (): void {
    $source = ScratchRepository::make();
    $work = ScratchDirectory::make().'/work';
    $source->git('symbolic-ref', 'HEAD', 'refs/heads/trunk');
    $source->write('packages/demo/src/Kept.php', "<?php\n")->commit('first');
    $source->write('README.md', "second\n")->commit('second');

    $process = new Process([Phpstan::root().'/docker/ci-entry.sh', 'bash', '-c', 'echo ran'],
        null, ['CMS_CI_SOURCE' => $source->root.'/.git', 'CMS_CI_WORK' => $work, 'CMS_CI_BASE_REF' => false], null, 60);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->not->toContain('ran')
        ->and($process->getErrorOutput())->toContain('ci-entry: CMS_CI_BASE_REF is not set, HEAD is not main, and neither origin/main nor main names a commit in the mounted repository.')
        ->and($work)->not->toBeDirectory();
});
