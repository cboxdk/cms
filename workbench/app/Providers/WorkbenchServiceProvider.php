<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Env;
use Illuminate\Support\ServiceProvider;

/**
 * Boots the workbench application that the packages are tested and developed against.
 *
 * It wires the two Postgres roles from compose.yaml (PRD 4.2, GUARDRAILS 6):
 * `pgsql` connects as the app role, which the application and the tests use, and
 * `pgsql_owner` connects as the owner role, which runs migrations. Both use the
 * dedicated schema instead of Laravel's default search_path of public.
 */
final class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $config = $this->app->make(Repository::class);

        $app = $config->get('database.connections.pgsql');

        if (! is_array($app)) {
            return;
        }

        $app['search_path'] = self::env('DB_SCHEMA', 'cms');

        $config->set('database.connections.pgsql', $app);
        $config->set('database.connections.pgsql_owner', array_merge($app, [
            'username' => self::env('DB_OWNER_USERNAME', 'cms_owner'),
            'password' => self::env('DB_OWNER_PASSWORD', ''),
        ]));
    }

    private static function env(string $key, string $default): string
    {
        $value = Env::get($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
