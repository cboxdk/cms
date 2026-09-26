<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Doctor;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Why a doctor check failed, which decides the exit code of `cms:doctor` (PRD 3.3).
 *
 * Violation: the configuration is invalid or the runtime contract is broken. Trying again does not
 * help; someone has to change something. Unavailable: a dependency such as Postgres or Valkey
 * cannot be reached right now. Trying again later may help.
 */
#[Experimental]
enum FailureKind: string
{
    case Violation = 'violation';
    case Unavailable = 'unavailable';
}
