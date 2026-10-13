<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Validation;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What happens to the value of a conditional field while its condition does not hold (gap G3).
 * Every conditional field declares it, so no default is decided for it (open decision 17).
 */
#[Experimental]
enum WhenHidden: string
{
    /**
     * A hidden field holds no value: the validators refuse one, so a form clears the input when it
     * hides it.
     */
    case Clear = 'clear';

    /**
     * A hidden field keeps the value it holds: the validators check it as they check any value,
     * but never require one.
     */
    case Keep = 'keep';
}
