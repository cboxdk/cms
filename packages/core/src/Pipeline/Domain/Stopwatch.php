<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * Real, monotonic time for the hooks' budgets (PRD 6.3): the command pipeline reads it before and
 * after each hook. It is not the Clock, which may be set, frozen or stepped back; a duration
 * measured with it never runs backwards.
 */
#[Internal]
interface Stopwatch
{
    /**
     * A monotonic reading in nanoseconds from an arbitrary start. Only the difference of two
     * readings means anything.
     */
    public function nanoseconds(): int;
}
