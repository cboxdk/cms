<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Identity\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\ActorDirectory;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Mutations\ActorDeactivated;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Core\Identity\Domain\Commands\DeactivateActor;
use Cbox\Cms\Core\Identity\Domain\Dto\DeactivateActorAggregates;
use Override;

/**
 * The write action of actor.deactivate (PRD 5.16, 6.2). resolve() reads the actor through the
 * ActorDirectory, from the primary; plan() deactivates it with the command's source when it is
 * active or pending, and plans nothing when it does not exist or is deactivated or deprovisioned
 * already, which the kernel answers as a rejection: a deactivation of a deactivated actor has no
 * effect. The kernel reads the calling actor too, and at commit checks both at the versions read.
 *
 * It is exposed on no surface: the kernel's own issuers call it. The surfaces that deactivate an
 * actor, the panel and the IdP's signals, come with blocks B1 and B6.
 *
 * @implements WriteAction<DeactivateActor, DeactivateActorAggregates>
 */
#[Action(handles: DeactivateActor::class)]
#[Internal]
final readonly class DeactivateActorAction implements WriteAction
{
    public function __construct(private ActorDirectory $actors) {}

    /**
     * @param  DeactivateActor  $command
     */
    #[Override]
    public function resolve(Command $command): DeactivateActorAggregates
    {
        return new DeactivateActorAggregates($command->actor, $this->actors->find($command->actor));
    }

    /**
     * @param  DeactivateActor  $command
     * @param  DeactivateActorAggregates  $aggregates
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        return $aggregates->deactivatable()
            ? new Plan(new ActorDeactivated($command->actor, $command->source))
            : Plan::empty();
    }
}
