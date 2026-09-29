<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Validation;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What the validated input is for (PRD 11.12 point 1, invariant 36): a write that creates or
 * revises an entry, or a release, which also requires the extension fields marked required.
 */
#[Experimental]
enum ValidationStage: string
{
    case Write = 'write';

    case Release = 'release';
}
