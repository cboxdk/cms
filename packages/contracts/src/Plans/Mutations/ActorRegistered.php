<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Plans\Mutations;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorProfile;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Plans\ClassifiedMutation;
use Override;

/**
 * An actor is registered (PRD 5.16): it is created pending, at version 1 and credential generation
 * 1, with its class, its profile and, for a service actor, the person responsible for it. It can
 * run nothing until actor.activate makes it active.
 *
 * The profile is personal data (ActorProfile::CLASSIFICATION), so a hook whose access does not
 * allow it sees the mutation without it (withoutClassified()); a mutation without a profile is
 * never written.
 */
#[Experimental]
final readonly class ActorRegistered implements ClassifiedMutation
{
    public function __construct(
        public ActorId $actor,
        public ActorClass $class,
        public ?ActorProfile $profile,
        public ?ActorId $responsible = null,
    ) {}

    #[Override]
    public function aggregate(): AggregateRef
    {
        return $this->actor;
    }

    #[Override]
    public function classification(): ClassificationAccess
    {
        return ActorProfile::CLASSIFICATION;
    }

    #[Override]
    public function withoutClassified(): self
    {
        return new self($this->actor, $this->class, null, $this->responsible);
    }
}
