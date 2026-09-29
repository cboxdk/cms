<?php

declare(strict_types=1);

namespace Examples\Unit\Addons\Reviews;

use Cbox\Cms\Contracts\Addons\AddonManifest;
use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Addons\CoreApiVersion;
use Cbox\Cms\Contracts\Build\DeclaresAddon;
use Cbox\Cms\Contracts\Build\DeclaresScanRoots;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Illuminate\Support\ServiceProvider;

/**
 * The same addon with a manifest that allows no hook, so cms:build refuses the hook in its scan
 * root.
 */
final class UnlistedHookServiceProvider extends ServiceProvider implements DeclaresAddon, DeclaresScanRoots
{
    public function scanRoots(): array
    {
        return [new ScanRoot('acme/cms-reviews', __DIR__)];
    }

    public function addonManifest(): AddonManifest
    {
        return new AddonManifest('acme/cms-reviews', new AddonNamespace('reviews'), new CoreApiVersion(1, 0), __DIR__.'/docs');
    }
}
