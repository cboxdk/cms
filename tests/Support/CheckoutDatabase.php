<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support;

use Cbox\Cms\Testkit\Postgres\Boundary\CheckoutRoot;
use Cbox\Cms\Testkit\Postgres\TestDatabaseName;
use Illuminate\Contracts\Config\Repository;

/**
 * What the tests of each suite see of this checkout's own Postgres test database.
 */
final class CheckoutDatabase
{
    /**
     * This checkout's database: cms_test and the hash of the checkout's real path.
     */
    public static function name(): string
    {
        return TestDatabaseName::for('cms_test', CheckoutRoot::current());
    }

    /**
     * The database of every configured pgsql connection, by connection name.
     *
     * @return array<string, string>
     */
    public static function pgsqlDatabases(Repository $config): array
    {
        $connections = $config->get('database.connections');
        $databases = [];

        foreach (is_array($connections) ? $connections : [] as $name => $settings) {
            if (is_array($settings) && ($settings['driver'] ?? null) === 'pgsql') {
                $databases[(string) $name] = is_string($settings['database'] ?? null) ? $settings['database'] : '';
            }
        }

        ksort($databases);

        return $databases;
    }
}
