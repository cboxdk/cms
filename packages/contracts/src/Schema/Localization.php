<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Schema;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * How a type is localized (PRD 10.1), the capability `localization` of its blueprint. The first
 * edition of the blueprint schema v1 has `none` only.
 */
#[Experimental]
enum Localization: string
{
    case None = 'none';
}
