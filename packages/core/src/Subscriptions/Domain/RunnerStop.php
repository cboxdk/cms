<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Subscriptions\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * Whether the event runner should stop, such as after SIGTERM. The runner asks between batches,
 * so a stop never cuts a batch in half.
 */
#[Internal]
interface RunnerStop
{
    public function requested(): bool;
}
