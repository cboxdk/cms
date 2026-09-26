<?php

declare(strict_types=1);

/*
 * Drops the Postgres test database of a checkout, cms_test_<hash of its real path>, as the owner
 * role connected to the configured test database of this checkout's phpunit.xml and environment.
 * `composer check:selftest` runs it for its worktree before it removes the worktree.
 *
 *   php tools/bin/drop-test-database.php <checkout root>
 *
 * Exits 0 when the database is gone, whether or not it existed, 1 when the drop failed and 2 on a
 * usage error.
 */

use Cbox\Cms\Testkit\Postgres\TestDatabase;
use Cbox\Cms\Testkit\Postgres\TestDatabaseName;
use Cbox\Cms\Tooling\Check\Boundary\CommandLine;
use Cbox\Cms\Tooling\TestDatabase\Boundary\PhpunitDatabase;

$root = (string) realpath(dirname(__DIR__, 2));

require $root.'/vendor/autoload.php';

$arguments = CommandLine::arguments();

if (count($arguments) !== 1 || ! is_dir($arguments[0])) {
    fwrite(STDERR, "Usage: php tools/bin/drop-test-database.php <checkout root>, a directory that exists.\n");
    exit(2);
}

try {
    $owner = PhpunitDatabase::owner($root.'/phpunit.xml', getenv());
    $name = TestDatabaseName::for($owner->database, $arguments[0]);
    $dropped = TestDatabase::drop($owner, $arguments[0]);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}

fwrite(STDOUT, $dropped
    ? "Dropped the test database {$name} of {$arguments[0]}.\n"
    : "The test database {$name} of {$arguments[0]} does not exist.\n");
exit(0);
