<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Schema;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Whether a type's entries have stages (PRD 4.1), the capability `stages` of its blueprint: none,
 * or a draft that is released.
 */
#[Experimental]
enum Stages: string
{
    case None = 'none';
    case DraftRelease = 'draft-release';
}
