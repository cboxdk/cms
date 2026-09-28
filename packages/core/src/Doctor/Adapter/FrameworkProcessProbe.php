<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Doctor\Domain\Probes\ProcessProbe;
use Cbox\Cms\Core\Process\Boundary\ProcessWorkload;
use Cbox\Cms\Core\Process\Domain\Workload;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Override;

/**
 * The application's configuration, and what the process runs as ProcessWorkload reads it, the
 * same reading that CoreServiceProvider refuses the owner connection by. It only looks: it never
 * opens a connection.
 */
#[Internal]
final readonly class FrameworkProcessProbe implements ProcessProbe
{
    public function __construct(
        private Application $app,
        private Repository $config,
    ) {}

    #[Override]
    public function connectionConfigured(string $name): bool
    {
        return is_array($this->config->get('database.connections.'.$name));
    }

    #[Override]
    public function workload(): Workload
    {
        return ProcessWorkload::of($this->app);
    }
}
