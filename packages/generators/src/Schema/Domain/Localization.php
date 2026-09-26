<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * How a type is localized (PRD 10.1). The first edition of the blueprint schema v1 has `none` only.
 */
#[Internal]
enum Localization: string
{
    case None = 'none';
}
