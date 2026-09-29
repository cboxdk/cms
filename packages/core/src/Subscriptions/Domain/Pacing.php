<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Subscriptions\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * Real time for the event runner: a monotonic count of milliseconds, which measures a batch
 * against its budget and schedules the next try after a failure, and a wait. It is not the Clock:
 * a wait and a budget are real durations, which a Clock that is set or frozen must not change.
 */
#[Internal]
interface Pacing
{
    /**
     * Milliseconds since an arbitrary start that never moves back.
     */
    public function milliseconds(): int;

    public function sleep(int $milliseconds): void;
}
