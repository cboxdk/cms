<?php

declare(strict_types=1);

namespace Cbox\Cms\Http;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Build\DeclaresScanRoots;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Http\Inertia\Domain\InertiaActions;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Registers the http package in a Laravel application. Loaded through package discovery.
 *
 * Declares the package's classes as a scan root for cms:build (PRD 13.2), and binds the actions the
 * Inertia profile exposes, read once per process from the compiled registry, which refuses a
 * registry that breaks the parity of the panel and REST.
 */
#[Internal]
final class HttpServiceProvider extends ServiceProvider implements DeclaresScanRoots
{
    public const string PACKAGE = 'cboxdk/cms';

    #[Override]
    public function register(): void
    {
        $this->app->singleton(
            InertiaActions::class,
            static fn (Application $app): InertiaActions => new InertiaActions($app->make(CompiledRegistry::class)),
        );
    }

    public function scanRoots(): array
    {
        return [new ScanRoot(self::PACKAGE, __DIR__)];
    }
}
