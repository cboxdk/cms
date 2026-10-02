<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Plans\Mutations;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Plans\Mutation;
use Override;

/**
 * A grant ends (PRD 5.10, 5.16), as a deactivation ends an actor's grants: from the commit on it
 * gives nothing, and it stays, ended by the changeset, so the access report can show it.
 */
#[Experimental]
final readonly class GrantRevoked implements Mutation
{
    public function __construct(public GrantId $grant) {}

    #[Override]
    public function aggregate(): AggregateRef
    {
        return $this->grant;
    }
}
