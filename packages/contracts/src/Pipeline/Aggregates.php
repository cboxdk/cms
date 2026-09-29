<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Pipeline;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What WriteAction::resolve() read for one command: the aggregates the command touches, as the
 * action's own final readonly class, and the version each was read at (PRD 6.2 phase 1). The
 * kernel checks those versions at commit, so a plan made from stale aggregates never commits.
 */
#[Experimental]
interface Aggregates
{
    /**
     * Every aggregate that was read, with its version, including those that did not exist.
     */
    public function versions(): ReadVersions;
}
