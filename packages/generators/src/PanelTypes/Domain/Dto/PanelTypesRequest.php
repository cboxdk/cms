<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\PanelTypes\Domain\Dto;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * What cms:panel:types writes the types of: the namespace of an installed addon.
 */
#[Internal]
final readonly class PanelTypesRequest
{
    public function __construct(public AddonNamespace $namespace) {}
}
