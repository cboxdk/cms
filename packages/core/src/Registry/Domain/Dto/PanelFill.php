<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\Scope;
use Cbox\Cms\Core\Registry\Domain\InvalidRegistryEntry;

/**
 * One contribution to a panel point in the registry: its id, whose first segment is the addon's
 * namespace (`cms` for the core's own), the Composer package that contributes it, its priority,
 * with the lowest rendered first, and the scope it is narrowed to.
 */
#[Experimental]
final readonly class PanelFill
{
    public string $package;

    public function __construct(
        public ContributionId $contribution,
        string $package,
        public int $priority,
        public Scope $scope,
    ) {
        $this->package = InvalidRegistryEntry::checkPackage($package);
    }

    public function addon(): AddonNamespace
    {
        return $this->contribution->namespace();
    }
}
