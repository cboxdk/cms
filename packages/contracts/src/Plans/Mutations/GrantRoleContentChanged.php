<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Plans\Mutations;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Plans\Mutation;
use Override;

/**
 * The role of a grant changed what it gives (PRD 5.10): the grant keeps its actor, role, node,
 * effect and locales and moves to its next version, so what its actor may do is compiled again,
 * as for a grant given or ended.
 */
#[Experimental]
final readonly class GrantRoleContentChanged implements Mutation
{
    public function __construct(public GrantId $grant) {}

    #[Override]
    public function aggregate(): AggregateRef
    {
        return $this->grant;
    }
}
