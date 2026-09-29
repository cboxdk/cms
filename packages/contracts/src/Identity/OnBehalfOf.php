<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\ActorId;

/**
 * The rule of an on-behalf-of chain (PRD 6.1): it never holds the actor itself, and never holds an
 * actor twice.
 */
#[Internal]
final readonly class OnBehalfOf
{
    /**
     * @param  list<ActorId>  $chain
     *
     * @throws InvalidIdentity when the chain holds the actor or an actor twice
     */
    public static function check(ActorId $actor, array $chain): void
    {
        $seen = [$actor->toString() => true];

        foreach ($chain as $link) {
            if (isset($seen[$link->toString()])) {
                throw InvalidIdentity::chain($link);
            }

            $seen[$link->toString()] = true;
        }
    }
}
