<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\PanelPoints;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * How an action asks before it runs its command (PRD 13.4): not at all, with a confirmation, as a
 * dry run whose report the viewer confirms, or through the generic command form, prefilled.
 */
#[Experimental]
enum Confirm: string
{
    case None = 'none';
    case Confirm = 'confirm';
    case DryRun = 'dry_run';
    case Form = 'form';
}
