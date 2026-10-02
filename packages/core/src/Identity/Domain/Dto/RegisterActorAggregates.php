<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Identity\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * What actor.register read (PRD 6.2 phase 1): the actor to register, as the ActorDirectory gave it,
 * or null when no actor has the id, and, for a command that names one, the person responsible, or
 * null when no actor has that id. The kernel checks at commit that the actor is still absent and
 * the responsible person still at the version read, so a deactivation of the responsible person
 * and the registration commit one after the other.
 */
#[Internal]
final readonly class RegisterActorAggregates implements Aggregates
{
    public function __construct(
        public ActorId $actor,
        public ?Actor $current,
        public ?ActorId $responsibleId,
        public ?Actor $responsible,
    ) {}

    /**
     * Whether the responsible person read is an active staff actor.
     */
    public function responsibleIsActiveStaff(): bool
    {
        return $this->responsible instanceof Actor
            && $this->responsible->class === ActorClass::Staff
            && $this->responsible->isActive();
    }

    #[Override]
    public function versions(): ReadVersions
    {
        $reads = [new ReadVersion($this->actor, $this->version($this->current))];

        if ($this->responsibleId instanceof ActorId && ! $this->responsibleId->equals($this->actor)) {
            $reads[] = new ReadVersion($this->responsibleId, $this->version($this->responsible));
        }

        return new ReadVersions(...$reads);
    }

    /**
     * Anywhere: an actor is not in the tree.
     */
    #[Override]
    public function authorizationScope(): AuthorizationScope
    {
        return AuthorizationScope::anywhere();
    }

    private function version(?Actor $actor): ?AggregateVersion
    {
        return $actor instanceof Actor ? new AggregateVersion($actor->version) : null;
    }
}
