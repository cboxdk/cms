<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\PanelThemes\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The tier of a design token: the primitive palette, which only the kit sets, and the semantic and
 * component tiers, which a theme may set.
 */
#[Experimental]
enum Tier: string
{
    case Primitive = 'primitive';
    case Semantic = 'semantic';
    case Component = 'component';
}
