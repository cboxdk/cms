<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Postgres\Boundary;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Testkit\Postgres\TestDatabaseName;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * Points the application's Postgres connections at the checkout's own test database.
 *
 * The configured database of the default connection, such as `cms_test`, is the base. Every pgsql
 * connection that names the base is changed to name TestDatabaseName::for(base, root) instead, so
 * the owner connection, the independent connections and the child processes the harness opens,
 * and the copies the doctor makes, all reach the checkout's database. In a parallel worker
 * (TestWorker), the database is the worker's own, TestDatabaseName::for(base, root, worker), and
 * a connection pointed at the checkout's database or another worker's is pointed at it too.
 * Running it again changes nothing. It only changes the configuration: a connection that is
 * already open keeps its database until it is purged.
 */
#[Experimental]
final readonly class CheckoutConnections
{
    /**
     * Points the connections at the database of this process: the checkout's, or in a worker of a
     * parallel run the worker's (TestWorker::current()). Returns it, or null when the default
     * connection is not pgsql.
     *
     * @throws InvalidArgumentException when the process is a parallel worker without a valid TEST_TOKEN
     */
    public static function point(Repository $config, string $root): ?string
    {
        return self::pointAt($config, $root, TestWorker::current());
    }

    /**
     * Points the connections at the checkout's database, or at the database of its parallel
     * worker $worker when that is not null. Returns it, or null when the default connection is
     * not pgsql.
     */
    public static function pointAt(Repository $config, string $root, ?int $worker): ?string
    {
        $default = $config->get('database.default');
        $connections = $config->get('database.connections');

        if (! is_string($default) || ! is_array($connections)) {
            return null;
        }

        $database = self::pgsqlDatabase($connections[$default] ?? null);

        if ($database === null) {
            return null;
        }

        $base = TestDatabaseName::base($database, $root);
        $derived = TestDatabaseName::for($base, $root, $worker);

        foreach ($connections as $name => $settings) {
            $named = self::pgsqlDatabase($settings);

            if ($named !== null && TestDatabaseName::base($named, $root) === $base) {
                $config->set('database.connections.'.$name.'.database', $derived);
            }
        }

        return $derived;
    }

    private static function pgsqlDatabase(mixed $settings): ?string
    {
        if (! is_array($settings) || ($settings['driver'] ?? null) !== 'pgsql') {
            return null;
        }

        $database = $settings['database'] ?? null;

        return is_string($database) && $database !== '' ? $database : null;
    }
}
