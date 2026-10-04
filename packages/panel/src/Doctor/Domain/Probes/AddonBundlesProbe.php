<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Doctor\Domain\Probes;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use Cbox\Cms\Panel\Doctor\Domain\Dto\BundleState;

/**
 * The panel bundles of the installed addons as this process finds them on disk, against the
 * compiled registry.
 */
#[Internal]
interface AddonBundlesProbe
{
    /**
     * The state of each addon's bundle, sorted by namespace.
     *
     * @return list<BundleState>
     *
     * @throws RegistryCacheMissing
     * @throws MalformedRegistryCache
     */
    public function bundles(): array;
}
