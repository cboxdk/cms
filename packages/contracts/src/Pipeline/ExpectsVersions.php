<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Pipeline;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * A command that carries the versions its caller saw (PRD 6.1, invariant 11): for each aggregate
 * the caller read, the version it read it at, or that it did not exist. The kernel compares them
 * with what WriteAction::resolve() read in phase 1 and rejects the command with version_conflict
 * when one differs, so a caller that worked from a stale copy never overwrites a newer change. The
 * versions read in phase 1 are then checked again at commit (PRD 6.2 phase 7).
 *
 * Every aggregate the command expects a version of must be one the action's resolve() reads.
 */
#[Experimental]
interface ExpectsVersions extends Command
{
    /**
     * The versions the caller expects, each aggregate once.
     */
    public function expectedVersions(): ReadVersions;
}
