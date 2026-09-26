<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Ci;

use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Symfony\Component\Process\Process;

/*
 * bin/ci, the single CI entry script, and docker/ci-entry.sh, which runs it on a clean git
 * archive of HEAD in compose.ci.yaml. bin/ci runs here with fake composer, npm, php, node, psql
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

    ScratchDirectory::write("{$bin}/composer", $record."\n".<<<'SH'
        if [[ "$1" == check ]]; then
            printf 'Gate 1  Pint and Prettier\n\nSummary\n  Gate 1   pass      Pint and Prettier\n'
            exit "${FAKE_CHECK_EXIT:-0}"
        fi
        SH);

    foreach (glob($bin.'/*') ?: [] as $file) {
        chmod($file, 0o755);
    }

    return $bin;
}

/**
 * @param  array<string, string>  $env
 * @return array{Process, list<string>}
 */
function runBinCi(string $scratch, array $env = []): array
{
    $process = new Process([Phpstan::root().'/bin/ci'], $scratch, [
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

it('installs the locked dependencies and runs composer check with the PR profile, nothing else', function (): void {
    $scratch = ScratchDirectory::make();
    [$process, $calls] = runBinCi($scratch);

    expect($process->getExitCode())->toBe(0)
        ->and(array_values(array_filter($calls, static fn (string $call): bool => ! str_contains($call, ' --version'))))->toBe([
            'composer install --no-interaction --no-progress --prefer-dist',
            'npm ci --no-audit --no-fund',
            "composer check -- --pr --report={$scratch}/build/check.json",
        ])
        ->and($process->getOutput())->toContain('runner: the test runner', 'Summary', 'wall time on the test runner; the GUARDRAILS 10 budget is 15 minutes')
        ->and((string) file_get_contents($scratch.'/build/check.log'))->toContain('Gate 1   pass');
});

it('exits 1 when a gate fails, after writing the summary for GitHub', function (): void {
    $scratch = ScratchDirectory::make();
    [$process] = runBinCi($scratch, ['FAKE_CHECK_EXIT' => '1', 'GITHUB_STEP_SUMMARY' => $scratch.'/summary.md']);
    $summary = (string) file_get_contents($scratch.'/summary.md');

    expect($process->getExitCode())->toBe(1)
        ->and($summary)->toContain("```text\nSummary\n  Gate 1   pass      Pint and Prettier\n```", 'the GUARDRAILS 10 budget is 15 minutes')
        ->and($summary)->not->toContain('Gate 1  Pint and Prettier');
});

it('stops before the gates when an install fails', function (): void {
    $scratch = ScratchDirectory::make();
    fakeTools($scratch);
    ScratchDirectory::write($scratch.'/bin/npm', "#!/usr/bin/env bash\n[[ \"\$1\" == ci ]] && exit 7\necho 10.0.0\n");
    chmod($scratch.'/bin/npm', 0o755);

    $process = new Process([Phpstan::root().'/bin/ci'], $scratch, [
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
    ], null, ['CMS_CI_SOURCE' => $source.'/.git', 'CMS_CI_WORK' => $work], null, 60);
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
