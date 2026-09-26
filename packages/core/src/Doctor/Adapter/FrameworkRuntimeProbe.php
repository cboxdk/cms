<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Doctor\Domain\Probes\RuntimeProbe;
use Illuminate\Contracts\Foundation\Application;
use Override;

/**
 * The PHP version of this process and the Laravel version of the application.
 */
#[Internal]
final readonly class FrameworkRuntimeProbe implements RuntimeProbe
{
    public function __construct(private Application $app) {}

    #[Override]
    public function phpVersion(): string
    {
        return PHP_VERSION;
    }

    #[Override]
    public function laravelVersion(): string
    {
        return $this->app->version();
    }
}
