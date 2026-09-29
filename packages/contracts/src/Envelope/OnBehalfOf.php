<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Envelope;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\ActorId;

/**
 * Whom the actor acts for (PRD 6.1): a chain kept in order, from the principal the actor acts for
 * directly to the person at its end, such as an agent acting for a token acting for an editor.
 * The chain is kept so four-eyes rules can be judged on the person behind a token or an agent.
 * An actor appears at most once; an empty chain means the actor acts for itself.
 */
#[Experimental]
final readonly class OnBehalfOf
{
    /** @var list<ActorId> */
    public array $chain;

    public function __construct(ActorId ...$chain)
    {
        $seen = [];

        foreach ($chain as $principal) {
            if (isset($seen[$principal->toString()])) {
                throw InvalidEnvelope::repeatedPrincipal($principal);
            }

            $seen[$principal->toString()] = $principal;
        }

        $this->chain = array_values($chain);
    }

    public function contains(ActorId $actor): bool
    {
        return array_any($this->chain, fn (ActorId $principal): bool => $principal->equals($actor));
    }

    public function isEmpty(): bool
    {
        return $this->chain === [];
    }
}
