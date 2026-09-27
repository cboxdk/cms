<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Postgres;

use Cbox\Cms\Testkit\Postgres\Boundary\ConnectionSettings;
use Cbox\Cms\Testkit\Postgres\TestDatabase;
use Cbox\Cms\Testkit\Postgres\TestDatabaseName;
use Cbox\Cms\Tests\Support\Tooling\DropDatabaseScripts;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/*
 * tools/bin/drop-test-database.php, which `composer check:selftest` runs for its worktree, drops a
 * checkout's test database as the owner role, and leaves this checkout's alone. The drop waits for
 * a forced checkpoint of the server every checkout shares, which can take longer than a minute
 * (tests/Support/Tooling/DropDatabaseScripts.php).
 */

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

/**
 * The drop script for the checkout at $root, not started yet. DROP DATABASE waits for a forced
 * checkpoint of the whole shared server, so it gets DropDatabaseScripts' timeout.
 *
 * @param  array<string, string>  $environment
 */
function dropProcess(string $root, array $environment = []): Process
{
    return DropDatabaseScripts::process([PHP_BINARY, 'tools/bin/drop-test-database.php', $root], $environment);
}

function dropScript(string $root): Process
{
    $process = dropProcess($root);
    $process->run();

    return $process;
}

it('drops the test database of another checkout, and says so when there is none', function (): void {
    $root = ScratchDirectory::make('cbox-cms-drop-test-');
    $owner = ConnectionSettings::of('pgsql_owner', config())->withDatabase('cms_test');
    $name = TestDatabase::provision($owner, ConnectionSettings::of('pgsql', config())->withDatabase('cms_test'), $root);
    $exists = static fn (string $database): bool => DB::connection('pgsql_owner')->scalar('select exists (select from pg_database where datname = ?)', [$database]) === true;

    try {
        expect($name)->toBe(TestDatabaseName::for('cms_test', $root))
            ->and($exists($name))->toBeTrue();

        $dropped = dropScript($root);

        expect($dropped->getExitCode())->toBe(0, $dropped->getErrorOutput())
            ->and($dropped->getOutput())->toBe("Dropped the test database {$name} of {$root}.\n")
            ->and($exists($name))->toBeFalse()
            ->and($exists(ConnectionSettings::of('pgsql', config())->database))->toBeTrue();

        $again = dropScript($root);

        expect($again->getExitCode())->toBe(0, $again->getErrorOutput())
            ->and($again->getOutput())->toBe("The test database {$name} of {$root} does not exist.\n");
    } finally {
        TestDatabase::drop($owner, $root);
    }
});

it('exits 1 with the reason when the server refuses the owner role', function (): void {
    $root = ScratchDirectory::make('cbox-cms-drop-test-');
    $process = dropProcess($root, ['DB_OWNER_PASSWORD' => 'not-the-password']);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('Could not drop the test database '.TestDatabaseName::for('cms_test', $root).' as the owner role cms_owner')
        ->and($process->getErrorOutput())->toContain('password authentication failed')
        ->and($process->getErrorOutput())->not->toContain('not-the-password');
});

it('waits for a drop as long as two forced checkpoints of the shared server take', function (): void {
    expect(dropProcess(ScratchDirectory::make('cbox-cms-drop-test-'))->getTimeout())
        ->toBeGreaterThanOrEqual((float) (2 * DropDatabaseScripts::LONGEST_FORCED_CHECKPOINT_SECONDS));
});
