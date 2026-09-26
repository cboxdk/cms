<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Build\DeclaresScanRoots;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Illuminate\Contracts\Foundation\Application;

/**
 * The scan roots the application's service providers declare through DeclaresScanRoots
 * (PRD 13.2). Deferred providers are registered first, so a deferred provider is not missed.
 */
#[Internal]
final readonly class ProviderScanRoots
{
    /**
     * @return list<ScanRoot>
     */
    public static function of(Application $app): array
    {
        $app->loadDeferredProviders();

        $roots = [];

        foreach ($app->getProviders(DeclaresScanRoots::class) as $provider) {
            if ($provider instanceof DeclaresScanRoots) {
                array_push($roots, ...$provider->scanRoots());
            }
        }

        return $roots;
    }
}
