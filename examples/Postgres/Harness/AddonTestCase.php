<?php

declare(strict_types=1);

namespace Examples\Postgres\Harness;

use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Testkit\Valkey\RealValkey;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Env;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;
use Override;

/**
 * The base class of an addon's tests against real Postgres and Valkey. The installed packages'
 * providers are discovered, as in an application, and WithWorkbench registers those of the
 * repository's testbench.yaml, which in cboxdk/cms's own repository, where cboxdk/cms is the root
 * package that discovery does not see, are cboxdk/cms's; so cboxdk/cms brings the core's
 * migrations and the partition command.
 *
 * The default connection `pgsql` is the app role, `pgsql_owner` the owner role and `pgsql_identity`
 * the identity role of the credential store of the local accounts, which cbox-cms.identity.connection
 * names, all on the database of the DB_* variables in phpunit.xml. The owner's search path lists the
 * credential store's schema cms_identity after the kernel's, and the identity role's search path is
 * that schema. The harness moves all three to the checkout's own test database, which it creates
 * as the owner role. The Redis connections come from the REDIS_* variables, as in any Laravel
 * application.
 */
abstract class AddonTestCase extends TestCase
{
    use RealPostgres;
    use RealValkey;
    use WithWorkbench;

    /** Discover the service providers of the installed packages. */
    #[Override]
    protected $enablesPackageDiscoveries = true;

    #[Override]
    protected function defineEnvironment($app): void
    {
        $config = $app->make(Repository::class);
        $appRole = [
            'driver' => 'pgsql',
            'host' => $this->env('DB_HOST', '127.0.0.1'),
            'port' => $this->env('DB_PORT', '5432'),
            'database' => $this->env('DB_DATABASE', 'cms_test'),
            'username' => $this->env('DB_USERNAME', 'cms_app'),
            'password' => $this->env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => $this->env('DB_SCHEMA', 'cms'),
            'sslmode' => 'prefer',
        ];

        $config->set('database.default', 'pgsql');
        $config->set('database.connections.pgsql', $appRole);
        $config->set('database.connections.pgsql_owner', [
            ...$appRole,
            'username' => $this->env('DB_OWNER_USERNAME', 'cms_owner'),
            'password' => $this->env('DB_OWNER_PASSWORD', ''),
            'search_path' => $appRole['search_path'].',cms_identity',
        ]);
        $config->set('database.connections.pgsql_identity', [
            ...$appRole,
            'username' => $this->env('DB_IDENTITY_USERNAME', 'cms_identity'),
            'password' => $this->env('DB_IDENTITY_PASSWORD', ''),
            'search_path' => 'cms_identity',
        ]);
    }

    private function env(string $key, string $default): string
    {
        $value = Env::get($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
