<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Providers;

use Cbox\Cms\Contracts\Build\DeclaresScanRoots;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

/**
 * A deferred provider that declares a scan root. cms:build must see it although nothing has
 * resolved its service.
 */
final class DeferredRootProvider extends ServiceProvider implements DeclaresScanRoots, DeferrableProvider
{
    public const string SERVICE = 'cms.tests.deferred-root';

    public function scanRoots(): array
    {
        return [new ScanRoot('acme/deferred', __DIR__)];
    }

    /**
     * @return list<string>
     */
    public function provides(): array
    {
        return [self::SERVICE];
    }
}
