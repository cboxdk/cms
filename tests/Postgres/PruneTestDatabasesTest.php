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
use Cbox\Cms\Tests\Support\Tooling\DropDatabaseScripts;
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
 *
 * The server is shared by every checkout, and another checkout runs this test at the same time
 * (GUARDRAILS 9). So the test gives the script a configured database of its own, with DB_DATABASE:
 * the test database of a scratch checkout, `cms_test_<hash>`. The script then lists only the
 * databases named `cms_test_<hash>_<12 hex digits>`, which this test made, and never drops
 * another checkout's `cms_test_<12 hex digits>` database, while another run's script never sees
 * this test's databases.
 */

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

/**
 * Runs the script with $configured as the configured database.
 */
function pruneScript(string $configured, string ...$arguments): Process
{
    $process = DropDatabaseScripts::process([PHP_BINARY, 'tools/bin/prune-test-databases.php', ...array_values($arguments)], ['DB_DATABASE' => $configured]);
    $process->run();

    return $process;
}

function pruneDatabaseExists(string $database): bool
{
    return DB::connection('pgsql_owner')->scalar('select exists (select from pg_database where datname = ?)', [$database]) === true;
}

/**
 * The lines of the report: the decisions sorted, which the script prints in the order of the
 * listing, and the summary last.
 *
 * @return list<string>
 */
function pruneReport(string $output): array
{
    $lines = explode("\n", rtrim($output, "\n"));
    $summary = array_pop($lines);
    sort($lines);

    return [...$lines, $summary];
}

function pruneSessionScalar(PDO $session, string $sql): mixed
{
    $statement = $session->query($sql);

    return $statement === false ? null : $statement->fetchColumn();
}

it('prunes only the database of a gone checkout on this host, also with an app-role session connected, and a dry run drops nothing', function (): void {
    $serverOwner = ConnectionSettings::of('pgsql_owner', config())->withDatabase('cms_test');
    $serverApp = ConnectionSettings::of('pgsql', config())->withDatabase('cms_test');
    $identity = ConnectionSettings::of('pgsql_identity', config())->withDatabase('cms_test');
    $host = TestDatabaseComment::of(Phpstan::root())->host;
    $ownerDb = DB::connection('pgsql_owner');
    $checkout = ConnectionSettings::of('pgsql', config())->database;
    /** @var list<string> $made */
    $made = [];
    $session = null;

    try {
        // The configured database of this test's prune, and every database below it, are this test's.
        $made[] = $configured = TestDatabase::provision($serverOwner, $serverApp, $identity, ScratchDirectory::make('cbox-cms-prune-configured-'));
        $owner = $serverOwner->withDatabase($configured);
        $app = $serverApp->withDatabase($configured);

        $roots = [
            'gone' => ScratchDirectory::make('cbox-cms-prune-gone-'),
            'existing' => ScratchDirectory::make('cbox-cms-prune-existing-'),
            'other host' => ScratchDirectory::make('cbox-cms-prune-other-host-'),
            'no comment' => ScratchDirectory::make('cbox-cms-prune-no-comment-'),
        ];
        $names = [];

        foreach ($roots as $key => $root) {
            $made[] = $names[$key] = TestDatabase::provision($owner, $app, $identity, $root);
        }

        $made[] = $current = TestDatabase::provision($owner, $app, $identity, CheckoutRoot::current());
        $ownerDb->statement(sprintf(
            'COMMENT ON DATABASE %s IS %s',
            TestDatabaseSetup::identifier($names['other host']),
            TestDatabaseSetup::literal(new TestDatabaseComment($roots['other host'], 'elsewhere.invalid')->encode()),
        ));
        $ownerDb->statement(sprintf('COMMENT ON DATABASE %s IS NULL', TestDatabaseSetup::identifier($names['no comment'])));

        // The checkouts of the database to drop, of the other host's and of the one without a
        // comment are removed; only the existing checkout stays.
        foreach (['gone', 'other host', 'no comment'] as $removed) {
            ScratchDirectory::delete($roots[$removed]);
        }

        // Another checkout's run of this test leaves the database of a gone checkout on this host
        // below the shared configured database cms_test, for its own prune, while this one runs.
        $foreignRoot = ScratchDirectory::make('cbox-cms-prune-foreign-');
        $made[] = $foreign = TestDatabase::provision($serverOwner, $serverApp, $identity, $foreignRoot);
        ScratchDirectory::delete($foreignRoot);

        $session = new PDO($app->withDatabase($names['gone'])->dsn(2), $app->username, $app->password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        expect(pruneSessionScalar($session, 'select current_user'))->toBe('cms_app')
            ->and($current)->toBe(TestDatabaseName::for($configured, CheckoutRoot::current()))
            ->and($foreign)->toMatch('/\Acms_test_[0-9a-f]{12}\z/');

        $decisions = [
            "keep {$configured}: the configured database, which the owner role connects to.",
            "keep {$current}: the test database of this checkout.",
            "drop {$names['gone']}: its checkout {$roots['gone']} on this host no longer exists.",
            "keep {$names['existing']}: its checkout {$roots['existing']} exists on this host.",
            "keep {$names['other host']}: its checkout {$roots['other host']} is on the host elsewhere.invalid, not on this host {$host}.",
            "keep {$names['no comment']}: it has no comment, so the testkit did not provision it or cannot tell its checkout.",
        ];
        sort($decisions);

        $dryRun = pruneScript($configured, '--dry-run');

        expect($dryRun->getExitCode())->toBe(0, $dryRun->getErrorOutput())
            ->and(pruneReport($dryRun->getOutput()))->toBe([...$decisions, 'Dry run: would drop 1 and keep 5; nothing was dropped.'])
            ->and(array_map(pruneDatabaseExists(...), $names))->toBe(['gone' => true, 'existing' => true, 'other host' => true, 'no comment' => true])
            ->and(pruneSessionScalar($session, 'select 1'))->toBe(1);

        $prune = pruneScript($configured);

        expect($prune->getExitCode())->toBe(0, $prune->getErrorOutput())
            ->and(pruneReport($prune->getOutput()))->toBe([...$decisions, 'Dropped 1 and kept 5.'])
            ->and(array_map(pruneDatabaseExists(...), $names))->toBe(['gone' => false, 'existing' => true, 'other host' => true, 'no comment' => true])
            ->and(pruneDatabaseExists($configured))->toBeTrue()
            ->and(pruneDatabaseExists($current))->toBeTrue()
            ->and(pruneDatabaseExists($checkout))->toBeTrue()
            ->and(pruneDatabaseExists($foreign))->toBeTrue()
            ->and(static fn (): mixed => pruneSessionScalar($session, 'select 1'))->toThrow(PDOException::class);
    } finally {
        $session = null;

        // The databases below the configured one first, then the configured one.
        foreach (array_reverse($made) as $name) {
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
