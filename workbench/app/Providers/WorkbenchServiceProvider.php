<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Env;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Cms\Generated\GeneratedTypesServiceProvider;

/**
 * Boots the workbench application that the packages are tested and developed against.
 *
 * It wires the two Postgres roles from compose.yaml (PRD 4.2, GUARDRAILS 6):
 * `pgsql` connects as the app role, which the application and the tests use, and
 * `pgsql_owner` connects as the owner role, which runs migrations. Both use the
 * dedicated schema instead of Laravel's default search_path of public. The workbench is a
 * development application whose console runs the migrations and partition maintenance itself, so
 * its console processes get `pgsql_owner`, and the environment of its tests (phpunit.xml) and of
 * vendor/bin/testbench (testbench.yaml) declares them the maintenance process for cms:doctor with
 * CBOX_CMS_MAINTENANCE_PROCESS=true. A process of the workbench that serves HTTP, such as
 * `testbench serve`, does not get `pgsql_owner`: the core refuses to boot a process that serves
 * HTTP or runs queued jobs with the owner connection (PRD 4.2), so a queue worker of the workbench
 * does not boot either, and a production installation gives it to its maintenance process only.
 *
 * It also points cms:generate (PRD 11.12) at the workbench's schema root, owner app, and its
 * committed generated code (GUARDRAILS 2.6), relative to the monorepo root, and cms:doctor at the
 * monorepo's vendor/composer/installed.json and at the root, where package.json and node_modules
 * are for --dev. Testbench's application links vendor/ into its base path only while a command runs, so
 * the default below the base path is not there in the tests.
 *
 * It registers the service provider that cms:generate writes from the workbench's schema, which
 * binds the TypeCatalog contract to the generated catalog and each fixture type's record factory,
 * as an application registers its own.
 */
final class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(GeneratedTypesServiceProvider::class);

        $config = $this->app->make(Repository::class);

        $config->set('cbox-cms.generators', [
            'root' => dirname(__DIR__, 3),
            'roots' => ['app' => 'workbench/schema'],
            'php_directory' => 'workbench/app/Cms/Generated',
            'php_namespace' => 'Workbench\\App\\Cms\\Generated',
            'typescript_directory' => 'workbench/resources/js/cms/generated',
        ]);

        $config->set('cbox-cms.doctor.project_path', dirname(__DIR__, 3));
        $config->set('cbox-cms.doctor.vendor_manifest', dirname(__DIR__, 3).'/vendor/composer/installed.json');

        $app = $config->get('database.connections.pgsql');

        if (! is_array($app)) {
            return;
        }

        $app['search_path'] = $this->env('DB_SCHEMA', 'cms');

        $config->set('database.connections.pgsql', $app);

        if (! $this->app->runningInConsole()) {
            return;
        }

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
