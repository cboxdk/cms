<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Identity\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * What actor.deactivate read (PRD 6.2 phase 1): the actor to deactivate, as the ActorDirectory
 * gave it, or null when no actor has the id. The kernel checks at commit that the actor is still at
 * the version read, so a deactivation and any other change of the actor commit one after the other.
 */
#[Internal]
final readonly class DeactivateActorAggregates implements Aggregates
{
    public function __construct(
        public ActorId $actor,
        public ?Actor $current,
    ) {}

    /**
     * Whether the actor exists in a state a deactivation leaves: active, or pending.
     */
    public function deactivatable(): bool
    {
        return $this->current instanceof Actor
            && ($this->current->state === ActorState::Active || $this->current->state === ActorState::Pending);
    }

    #[Override]
    public function versions(): ReadVersions
    {
        return new ReadVersions(new ReadVersion(
            $this->actor,
            $this->current instanceof Actor ? new AggregateVersion($this->current->version) : null,
        ));
    }

    /**
     * Anywhere: an actor is not in the tree.
     */
    #[Override]
    public function authorizationScope(): AuthorizationScope
    {
        return AuthorizationScope::anywhere();
    }
}
