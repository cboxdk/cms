<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\ActorId;

/**
 * An actor as a verified credential presents it (PRD 5.16, 6.1): the actor, the actors it acts on
 * behalf of, the issuer kind of its credential and the credential's classification ceiling.
 *
 * The chain is kept in order, from the actor the actor acts for directly to the last, so four-eyes
 * rules can be judged on the person behind an agent or a token (invariant 16). It never holds the
 * actor itself or an actor twice. The ceiling never exceeds what the issuer kind permits: an
 * agent's is at most confidential (PRD 2.31).
 */
#[Experimental]
final readonly class ActorPrincipal implements Principal
{
    /**
     * @param  list<ActorId>  $onBehalfOf
     */
    public function __construct(
        public ActorId $actor,
        public array $onBehalfOf,
        public IssuerKind $issuerKind,
        public ClassificationAccess $ceiling,
    ) {
        OnBehalfOf::check($actor, $onBehalfOf);

        if (! $issuerKind->permits($ceiling)) {
            throw InvalidIdentity::ceiling($issuerKind, $ceiling);
        }
    }

    public function classificationCeiling(): ClassificationAccess
    {
        return $this->ceiling;
    }
}
