<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Contributions\Domain\Dto;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Where an addon's page is asked for (PRD 13.4): the addon's namespace and the path below
 * `<prefix>/x/<namespace>/`, as a PageContribution declares it.
 */
#[Experimental]
final readonly class AddonPageRef
{
    public function __construct(
        public AddonNamespace $addon,
        public string $path,
    ) {}
}
