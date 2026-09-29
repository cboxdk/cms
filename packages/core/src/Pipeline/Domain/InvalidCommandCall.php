<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use LogicException;

/**
 * A call the command pipeline refuses because the code that made it is wrong, not its input: a
 * surface that hands it an access context of another principal than the envelope names, or an
 * action whose plan or expected versions name an aggregate its resolve() did not read. Nothing is
 * committed; fix the surface or the action.
 */
#[Internal]
final class InvalidCommandCall extends LogicException
{
    public static function principalMismatch(ActorId $actor): self
    {
        return new self(sprintf('The access context is not the principal of the envelope, the actor %s with its on-behalf-of chain in order. A surface builds both from the same verified credential.', $actor->toString()));
    }

    public static function unreadAggregate(string $action, AggregateRef $aggregate): self
    {
        return new self(sprintf('The plan of %s changes the aggregate "%s", which its resolve() did not read. An action reads every aggregate it changes, so the commit can check its version.', $action, $aggregate->aggregateKey()));
    }

    public static function unknownOutcome(string $class): self
    {
        return new self(sprintf('The commit answered with %s, which is not one of the two commit outcomes.', $class));
    }

    public static function unexpectedExpectation(string $command, AggregateRef $aggregate): self
    {
        return new self(sprintf('The command %s expects a version of the aggregate "%s", which its action\'s resolve() did not read.', $command, $aggregate->aggregateKey()));
    }
}
