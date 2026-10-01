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

    /**
     * Where the kernel authorizes the command (PRD 5.10): the nodes it acts on with their locales,
     * taken from what was read, or anywhere() when what it acts on names no node or read as absent.
     */
    public function authorizationScope(): AuthorizationScope;
}
