<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests;

use Cbox\Cms\Testkit\Postgres\Boundary\CheckoutConnections;
use Cbox\Cms\Testkit\Postgres\Boundary\CheckoutRoot;
use Cbox\Cms\Testkit\Postgres\Boundary\TestWorker;
use Illuminate\Contracts\Config\Repository;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;
use Override;

/**
 * Boots the workbench application from testbench.yaml with package discovery on,
 * so the packages load the same way they do in an installed application.
 *
 * Every suite runs against this checkout's own Postgres test database: before the first
 * connection opens, every pgsql connection that names the configured database (DB_DATABASE,
 * cms_test) is pointed at cms_test_<hash of the checkout's path>, so two checkouts never share
 * rows, and in a worker of a parallel run at cms_test_<hash>_w<worker>, so two workers never
 * share rows either. The Postgres suite's harness provisions and migrates it.
 */
abstract class TestCase extends Orchestra
{
    use WithWorkbench;

    /**
     * Load the providers that the packages declare under extra.laravel.providers,
     * as an application would. Testbench ignores them by default.
     *
     * @var bool
     */
    #[Override]
    protected $enablesPackageDiscoveries = true;

    #[Override]
    protected function defineEnvironment($app): void
    {
        CheckoutConnections::point($app->make(Repository::class), CheckoutRoot::current(), TestWorker::current());
    }
}
