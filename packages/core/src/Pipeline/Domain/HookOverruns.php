<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Pipeline\Domain\Dto\HookOverrun;

/**
 * Where a hook that went over its budget, or over its command's, is recorded (PRD 6.3, 13.6), with
 * its class and package, so which package makes commands slow is a lookup. The command pipeline
 * records the overrun before it rejects the command. Recording never throws for an overrun.
 */
#[Internal]
interface HookOverruns
{
    public function record(HookOverrun $overrun): void;
}
