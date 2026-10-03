<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\PanelPoints;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * How many contributions to a point the host renders: every one in order (Many), the first `max`
 * in order (Max, with the point's max), or exactly one, the replacement that wins (Exclusive).
 */
#[Experimental]
enum Multiplicity: string
{
    case Many = 'many';
    case Max = 'max';
    case Exclusive = 'exclusive';
}
