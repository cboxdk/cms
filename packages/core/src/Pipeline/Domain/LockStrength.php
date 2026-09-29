<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * How the commit locks the row of an aggregate it checks the version of (PRD 6.2 phase 7): Share
 * for an aggregate the changeset only read, so commands that read the same aggregate commit side
 * by side while a change of it waits for them, and Update for one the changeset changes.
 */
#[Internal]
enum LockStrength: string
{
    case Share = 'share';
    case Update = 'update';
}
