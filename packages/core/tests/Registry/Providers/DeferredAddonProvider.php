<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Providers;

use Cbox\Cms\Contracts\Addons\AddonManifest;
use Cbox\Cms\Contracts\Build\DeclaresAddon;
use Cbox\Cms\Core\Tests\Registry\RegistryFixtures;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * A deferred provider that declares the fixture addon's manifest. cms:build must see it although
 * nothing has resolved its service.
 */
final class DeferredAddonProvider extends ServiceProvider implements DeclaresAddon, DeferrableProvider
{
    public const string SERVICE = 'cms.tests.deferred-addon';

    #[Override]
    public function addonManifest(): AddonManifest
    {
        return RegistryFixtures::addonManifest();
    }

    /**
     * @return list<string>
     */
    public function provides(): array
    {
        return [self::SERVICE];
    }
}
