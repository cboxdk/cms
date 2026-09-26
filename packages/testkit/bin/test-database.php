<?php

declare(strict_types=1);

/*
 * Provisions the test database of a checkout: the child process of
 * Cbox\Cms\Testkit\Postgres\TestDatabase::command().
 *
 * The parent passes the path of its Composer autoloader as the only argument and the payload,
 * the owner role's and the app role's connections and the checkout root, on standard input.
 * The work is in TestDatabaseMain. The file declares the testkit's namespace, because
 * TestDatabaseMain is internal to Cbox\Cms.
 */

namespace Cbox\Cms\Testkit\Postgres;

$autoload = $argv[1] ?? '';

if (! is_file($autoload)) {
    fwrite(STDERR, "Usage: php test-database.php <path to vendor/autoload.php>, with the payload on standard input.\n");

    exit(2); // TestDatabaseMain::INVALID; the class is not loadable yet.
}

require $autoload;

exit(TestDatabaseMain::run(
    (string) stream_get_contents(STDIN),
    static function (string $line): void {
        fwrite(STDOUT, $line);
    },
    static function (string $text): void {
        fwrite(STDERR, $text);
    },
));
