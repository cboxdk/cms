<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Domain;

/**
 * Decides, when its step is about to run, whether the steps before it already settled the
 * outcome, so running the command could not change it. The runner then passes the step without
 * running it and lists the note that says why, as with Step::passed(). It never fails a step and
 * never skips one whose command could still fail it: when in doubt it answers null, and the step
 * runs.
 */
interface StepPrecheck
{
    /**
     * A one-line note that says why the step passes without its command, or null to run it.
     */
    public function passedWithout(): ?string;
}
