<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Doctor;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The outcome of one doctor check.
 *
 * A check itself returns Pass or Fail. Skip comes from the doctor: a check that requires another
 * check is not run when that one did not pass.
 */
#[Experimental]
enum CheckStatus: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case Skip = 'skip';
}
