<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * Whether a type's entries have stages (PRD 4.1): none, or a draft that is released. The
 * capability `stages` of the blueprint schema v1.
 */
#[Internal]
enum Stages: string
{
    case None = 'none';

    case DraftRelease = 'draft-release';
}
