<?php

declare(strict_types=1);

/*
 * `composer test-db:prune`: drops the Postgres test databases of checkouts that were removed
 * without the selftest's clean-up, such as git worktrees removed by hand.
 *
 *   php tools/bin/prune-test-databases.php [--dry-run]
 *
 * It connects as the owner role to the configured test database of this checkout's phpunit.xml
 * and environment, as drop-test-database.php does, and looks at the configured database and every
 * database named `<configured>_<12 hex digits>`. It drops, with DROP DATABASE ... WITH (FORCE),
 * exactly those whose testkit comment names this host and a checkout path that no longer exists,
 * or no longer derives that name (Cbox\Cms\Tooling\TestDatabase\Domain\PrunePlan). It prints each
 * name with its verdict and the reason; --dry-run prints the same and drops nothing.
 *
 * Exits 0 when every drop succeeded, 1 when the server could not be read or a drop failed, and 2
 * on a usage error.
 */

use Cbox\Cms\Testkit\Postgres\ServiceCheck;
use Cbox\Cms\Testkit\Postgres\TestDatabaseName;
use Cbox\Cms\Tooling\Check\Boundary\CommandLine;
use Cbox\Cms\Tooling\TestDatabase\Adapter\PostgresTestDatabaseCatalog;
use Cbox\Cms\Tooling\TestDatabase\Boundary\FilesystemCheckouts;
use Cbox\Cms\Tooling\TestDatabase\Boundary\PhpunitDatabase;
use Cbox\Cms\Tooling\TestDatabase\Domain\PruneContext;
use Cbox\Cms\Tooling\TestDatabase\Domain\PrunePlan;

$root = (string) realpath(dirname(__DIR__, 2));

require $root.'/vendor/autoload.php';

$arguments = CommandLine::arguments();

if ($arguments !== [] && $arguments !== ['--dry-run']) {
    fwrite(STDERR, "Usage: php tools/bin/prune-test-databases.php [--dry-run]\n");
    exit(2);
}

$dryRun = $arguments === ['--dry-run'];

try {
    $owner = PhpunitDatabase::owner($root.'/phpunit.xml', getenv());
    $base = TestDatabaseName::base($owner->database, $root);
    $host = gethostname();
    $context = new PruneContext($base, TestDatabaseName::for($base, $root), $host === false ? '' : $host);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}

$catalog = new PostgresTestDatabaseCatalog($owner->withDatabase($base), ServiceCheck::CONNECT_TIMEOUT_SECONDS);

try {
    $plan = PrunePlan::for($catalog->databases(), $context, new FilesystemCheckouts);
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf(
        "Could not list the test databases as the owner role %s at %s:%d.\nReason: %s\n",
        $owner->username,
        $owner->host,
        $owner->port,
        preg_replace('/\s+/', ' ', trim($exception->getMessage())),
    ));
    exit(1);
}

foreach ($plan->decisions as $decision) {
    fwrite(STDOUT, $decision->line()."\n");
}

$drops = $plan->drops();
$kept = count($plan->decisions) - count($drops);

if ($dryRun) {
    fwrite(STDOUT, sprintf("Dry run: would drop %d and keep %d; nothing was dropped.\n", count($drops), $kept));
    exit(0);
}

$failed = 0;

foreach ($drops as $name) {
    try {
        $catalog->drop($name);
    } catch (Throwable $exception) {
        $failed++;
        fwrite(STDERR, sprintf("Could not drop %s: %s\n", $name, preg_replace('/\s+/', ' ', trim($exception->getMessage()))));
    }
}

fwrite(STDOUT, sprintf("Dropped %d and kept %d.%s\n", count($drops) - $failed, $kept, $failed === 0 ? '' : " {$failed} drops failed."));
exit($failed === 0 ? 0 : 1);
