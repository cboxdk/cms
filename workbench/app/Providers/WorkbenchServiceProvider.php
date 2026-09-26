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
 *
 * It also points cms:generate (PRD 11.12) at the workbench's fixture schema and its committed
 * generated code (GUARDRAILS 2.6), relative to the monorepo root, and cms:doctor at the monorepo's
 * vendor/composer/installed.json and at the root, where package.json and node_modules are for
 * --dev. Testbench's application links vendor/ into its base path only while a command runs, so
 * the default below the base path is not there in the tests.
 */
final class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $config = $this->app->make(Repository::class);

        $config->set('cms.generators', [
            'root' => dirname(__DIR__, 3),
            'schema' => 'workbench/schema/fixture.yaml',
            'php_directory' => 'workbench/app/Cms/Generated',
            'php_namespace' => 'Workbench\\App\\Cms\\Generated',
            'typescript_directory' => 'workbench/resources/js/cms/generated',
        ]);

        $config->set('cms.doctor.project_path', dirname(__DIR__, 3));
        $config->set('cms.doctor.vendor_manifest', dirname(__DIR__, 3).'/vendor/composer/installed.json');

        $app = $config->get('database.connections.pgsql');

        if (! is_array($app)) {
            return;
        }

        $app['search_path'] = $this->env('DB_SCHEMA', 'cms');

        $config->set('database.connections.pgsql', $app);
        $config->set('database.connections.pgsql_owner', array_merge($app, [
            'username' => $this->env('DB_OWNER_USERNAME', 'cms_owner'),
            'password' => $this->env('DB_OWNER_PASSWORD', ''),
        ]));
    }

    private function env(string $key, string $default): string
    {
        $value = Env::get($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
