<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests;

use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;
use Override;

/**
 * Boots the workbench application from testbench.yaml with package discovery on,
 * so the packages load the same way they do in an installed application.
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
}
