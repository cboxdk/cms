<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Plans;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;

/**
 * One typed change of a Plan (GUARDRAILS 2.1, PRD 6.2), such as RevisionCreated or HeadMoved:
 * a final readonly class of typed ids and values. A mutation describes the change; only the
 * kernel writes it, in phase 7, in the command's one transaction.
 */
#[Experimental]
interface Mutation
{
    /**
     * The aggregate the mutation changes. The kernel checks that the write read it, and bumps
     * its version at commit.
     */
    public function aggregate(): AggregateRef;
}
