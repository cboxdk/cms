<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * Which budget a hook went over (PRD 6.3): its own, from #[Hook]'s budgetMs, or the command's, the
 * 100 ms all hooks of one command have together.
 */
#[Internal]
enum OverrunKind: string
{
    case Hook = 'hook';
    case Command = 'command';
}
