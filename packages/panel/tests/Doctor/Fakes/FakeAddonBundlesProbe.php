<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Doctor\Fakes;

use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use Cbox\Cms\Panel\Doctor\Domain\Dto\BundleState;
use Cbox\Cms\Panel\Doctor\Domain\Probes\AddonBundlesProbe;
use Override;

/**
 * The state of the addons' bundles a test gives, or a registry that cannot be read.
 */
final readonly class FakeAddonBundlesProbe implements AddonBundlesProbe
{
    /**
     * @param  list<BundleState>  $bundles
     */
    public function __construct(
        private array $bundles = [],
        private bool $registryMissing = false,
    ) {}

    #[Override]
    public function bundles(): array
    {
        return $this->registryMissing ? throw RegistryCacheMissing::at('/srv/app/bootstrap/cache/cms') : $this->bundles;
    }
}
