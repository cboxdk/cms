<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Plans\Mutations;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\DeactivationSource;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Plans\Mutation;
use Override;

/**
 * An actor is deactivated (PRD 5.16): from the commit on, a command as the actor or on its
 * behalf is rejected, and a command it was running when the deactivation committed fails with a
 * conflict, because the actor is an aggregate every command reads (invariant 37). The source is
 * noted on the actor; a local command unless another is given.
 */
#[Experimental]
final readonly class ActorDeactivated implements Mutation
{
    public function __construct(
        public ActorId $actor,
        public DeactivationSource $source = DeactivationSource::Local,
    ) {}

    #[Override]
    public function aggregate(): AggregateRef
    {
        return $this->actor;
    }
}
