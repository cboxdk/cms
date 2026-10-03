<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Override;

/**
 * The set of grants an actor holds (PRD 5.10, invariant 31): its version is one, plus the versions
 * of every grant of the actor, ended ones included, plus the number that have ended, so it moves
 * with every grant given to the actor, every revocation, every change of a held role's permissions
 * and every deactivation. The escalation guard decides from the issuing actor's grants and from
 * those of each actor it acts on behalf of, so the kernel's authorizer reads their sets and the
 * commit finds a set changed meanwhile version_conflict. Every command that changes an actor's
 * grants (grant.assign, grant.revoke, role.set_permissions) reads the actor's set too, so the
 * commit's lock on it orders the two.
 *
 * Its key sorts after the actor's ("actor:" before "actor_grants:"), so the commit locks the actor
 * first.
 */
#[Internal]
final readonly class ActorGrantsRef implements AggregateRef
{
    public const string KIND = 'actor_grants';

    public function __construct(public ActorId $actor) {}

    /**
     * "actor_grants:" and the actor.
     */
    #[Override]
    public function aggregateKey(): string
    {
        return self::KIND.':'.$this->actor->toString();
    }
}
