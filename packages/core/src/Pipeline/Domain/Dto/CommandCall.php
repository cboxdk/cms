<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Core\Pipeline\Domain\InvalidCommandCall;

/**
 * One call of a write as a surface hands it to the command pipeline (GUARDRAILS 2.1, PRD 6.1): the
 * command, the envelope the surface built, and the AccessContext the kernel computed from the
 * verified principal. The principal is the envelope's actor with the envelope's on-behalf-of chain,
 * in the same order, so the actor the pipeline reads, authorizes and records is the one the
 * credential carried.
 */
#[Internal]
final readonly class CommandCall
{
    /**
     * @throws InvalidCommandCall when the access context is not the envelope's actor and chain
     */
    public function __construct(
        public Command $command,
        public Envelope $envelope,
        public AccessContext $access,
    ) {
        $principal = $access->principal;

        if (! $principal instanceof ActorPrincipal
            || ! $principal->actor->equals($envelope->actor)
            || ! $this->sameChain($principal->onBehalfOf, $envelope->onBehalfOf->chain)) {
            throw InvalidCommandCall::principalMismatch($envelope->actor);
        }
    }

    /**
     * @param  list<ActorId>  $one
     * @param  list<ActorId>  $other
     */
    private function sameChain(array $one, array $other): bool
    {
        return count($one) === count($other)
            && array_all($one, static fn (ActorId $actor, int $index): bool => $actor->equals($other[$index]));
    }
}
