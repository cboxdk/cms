<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The release state of a variant's head (PRD 5.6, 6.4): unreleased, released with a published
 * revision, or withdrawn, a takedown everywhere.
 */
#[Experimental]
enum ReleaseState: string
{
    case Unreleased = 'unreleased';
    case Released = 'released';
    case Withdrawn = 'withdrawn';
}
