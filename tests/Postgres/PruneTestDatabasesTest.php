<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Postgres;

use Cbox\Cms\Testkit\Postgres\Boundary\CheckoutRoot;
use Cbox\Cms\Testkit\Postgres\Boundary\ConnectionSettings;
use Cbox\Cms\Testkit\Postgres\Boundary\TestDatabaseComment;
use Cbox\Cms\Testkit\Postgres\Infrastructure\TestDatabaseSetup;
use Cbox\Cms\Testkit\Postgres\TestDatabase;
use Cbox\Cms\Testkit\Postgres\TestDatabaseName;
use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Symfony\Component\Process\Process;

/*
 * `composer test-db:prune` (tools/bin/prune-test-databases.php) drops the test databases of
 * checkouts removed without the selftest's clean-up, on the real server, as the owner role. It
 * drops a database whose testkit comment names this host and a checkout that is gone, also while
 * an app-role session is still connected to it, and keeps the configured database, this
 * checkout's database, the databases of other hosts and those without a comment. The verdicts
 * themselves are tested in tests/Feature/Tooling/PruneTestDatabasesPlanTest.php.
 */

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

function pruneScript(string ...$arguments): Process
{
    $process = new Process([PHP_BINARY, 'tools/bin/prune-test-databases.php', ...$arguments], Phpstan::root(), null, null, 300);
    $process->run();

    return $process;
}

function pruneDatabaseExists(string $database): bool
{
    return DB::connection('pgsql_owner')->scalar('select exists (select from pg_database where datname = ?)', [$database]) === true;
}

/**
 * The lines of the report that name one of $names, in its order.
 *
 * @param  list<string>  $names
 * @return list<string>
 */
function pruneLinesOf(string $output, array $names): array
{
    return array_values(array_filter(
        explode("\n", $output),
        static fn (string $line): bool => array_any($names, static fn (string $name): bool => str_contains($line, " {$name}: ")),
    ));
}

function pruneSessionScalar(PDO $session, string $sql): mixed
{
    $statement = $session->query($sql);

    return $statement === false ? null : $statement->fetchColumn();
}

it('prunes only the database of a gone checkout on this host, also with an app-role session connected, and a dry run drops nothing', function (): void {
    $owner = ConnectionSettings::of('pgsql_owner', config())->withDatabase('cms_test');
    $app = ConnectionSettings::of('pgsql', config())->withDatabase('cms_test');
    $host = TestDatabaseComment::of(Phpstan::root())->host;

    $roots = [
        'gone' => ScratchDirectory::make('cbox-cms-prune-gone-'),
        'existing' => ScratchDirectory::make('cbox-cms-prune-existing-'),
        'other host' => ScratchDirectory::make('cbox-cms-prune-other-host-'),
        'no comment' => ScratchDirectory::make('cbox-cms-prune-no-comment-'),
    ];
    $names = array_map(static fn (string $root): string => TestDatabase::provision($owner, $app, $root), $roots);
    $ownerDb = DB::connection('pgsql_owner');
    $ownerDb->statement(sprintf(
        'COMMENT ON DATABASE %s IS %s',
        TestDatabaseSetup::identifier($names['other host']),
        TestDatabaseSetup::literal(new TestDatabaseComment($roots['other host'], 'elsewhere.invalid')->encode()),
    ));
    $ownerDb->statement(sprintf('COMMENT ON DATABASE %s IS NULL', TestDatabaseSetup::identifier($names['no comment'])));

    // The checkouts of the database to drop, of the other host's and of the one without a comment
    // are removed; only the existing checkout stays.
    foreach (['gone', 'other host', 'no comment'] as $removed) {
        ScratchDirectory::delete($roots[$removed]);
    }

    $current = TestDatabaseName::for('cms_test', CheckoutRoot::current());
    $session = new PDO($app->withDatabase($names['gone'])->dsn(2), $app->username, $app->password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    try {
        expect(pruneSessionScalar($session, 'select current_user'))->toBe('cms_app');

        $expected = [
            'keep cms_test: the configured database, which the owner role connects to.',
            "keep {$current}: the test database of this checkout.",
            "drop {$names['gone']}: its checkout {$roots['gone']} on this host no longer exists.",
            "keep {$names['existing']}: its checkout {$roots['existing']} exists on this host.",
            "keep {$names['other host']}: its checkout {$roots['other host']} is on the host elsewhere.invalid, not on this host {$host}.",
            "keep {$names['no comment']}: it has no comment, so the testkit did not provision it or cannot tell its checkout.",
        ];
        sort($expected);
        $lines = static function (string $output) use ($names, $current): array {
            $found = pruneLinesOf($output, ['cms_test', $current, ...array_values($names)]);
            sort($found);

            return $found;
        };

        $dryRun = pruneScript('--dry-run');

        expect($dryRun->getExitCode())->toBe(0, $dryRun->getErrorOutput())
            ->and($lines($dryRun->getOutput()))->toBe($expected)
            ->and($dryRun->getOutput())->toContain('Dry run: would drop ', '; nothing was dropped.')
            ->and(array_map(pruneDatabaseExists(...), $names))->toBe(['gone' => true, 'existing' => true, 'other host' => true, 'no comment' => true])
            ->and(pruneSessionScalar($session, 'select 1'))->toBe(1);

        $prune = pruneScript();

        expect($prune->getExitCode())->toBe(0, $prune->getErrorOutput())
            ->and($lines($prune->getOutput()))->toBe($expected)
            ->and($prune->getOutput())->toMatch('/^Dropped \d+ and kept \d+\.$/m')
            ->and(array_map(pruneDatabaseExists(...), $names))->toBe(['gone' => false, 'existing' => true, 'other host' => true, 'no comment' => true])
            ->and(pruneDatabaseExists('cms_test'))->toBeTrue()
            ->and(pruneDatabaseExists($current))->toBeTrue()
            ->and(static fn (): mixed => pruneSessionScalar($session, 'select 1'))->toThrow(PDOException::class);
    } finally {
        unset($session);

        foreach ($names as $name) {
            $ownerDb->statement('DROP DATABASE IF EXISTS '.TestDatabaseSetup::identifier($name).' WITH (FORCE)');
        }
    }
});

it('exits 1 with the reason when the server refuses the owner role, and drops nothing', function (): void {
    $process = new Process([PHP_BINARY, 'tools/bin/prune-test-databases.php'], Phpstan::root(), ['DB_OWNER_PASSWORD' => 'not-the-password']);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toContain('Could not list the test databases as the owner role cms_owner', 'password authentication failed')
        ->and($process->getErrorOutput())->not->toContain('not-the-password');
});
