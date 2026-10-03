<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\PanelPoints;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Where a flow step runs in a command form (PRD 13.4): before the submit, or after the receipt.
 * The core's own confirmation and dry run always run last before the commit.
 */
#[Experimental]
enum StepPosition: string
{
    case BeforeSubmit = 'before_submit';
    case AfterReceipt = 'after_receipt';
}
