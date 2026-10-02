<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Plans\Mutations;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Plans\Mutation;
use Override;

/**
 * A pending actor becomes active (PRD 5.16): the last step of its registration, after its
 * credential is written. From the commit on it can run commands and reads as its grants allow.
 */
#[Experimental]
final readonly class ActorActivated implements Mutation
{
    public function __construct(public ActorId $actor) {}

    #[Override]
    public function aggregate(): AggregateRef
    {
        return $this->actor;
    }
}
