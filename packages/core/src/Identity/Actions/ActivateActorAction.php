<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Identity\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorDirectory;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\RefusesCommand;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Mutations\ActorActivated;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Identity\Domain\Commands\ActivateActor;
use Cbox\Cms\Core\Identity\Domain\Dto\ActivateActorAggregates;
use Override;

/**
 * The write action of actor.activate (PRD 5.16, 6.2). resolve() reads the actor through the
 * ActorDirectory; the kernel compares it with the version the caller read. refusals() refuses an
 * actor that is not pending with validation_failed, and plan() activates it.
 *
 * @implements WriteAction<ActivateActor, ActivateActorAggregates>
 * @implements RefusesCommand<ActivateActor, ActivateActorAggregates>
 */
#[Action(handles: ActivateActor::class, surfaces: [Surface::Rest, Surface::Inertia, Surface::Cli])]
#[Internal]
final readonly class ActivateActorAction implements RefusesCommand, WriteAction
{
    public function __construct(private ActorDirectory $actors) {}

    /**
     * @param  ActivateActor  $command
     */
    #[Override]
    public function resolve(Command $command): ActivateActorAggregates
    {
        return new ActivateActorAggregates($command->actor, $this->actors->find($command->actor));
    }

    /**
     * @param  ActivateActor  $command
     * @param  ActivateActorAggregates  $aggregates
     */
    #[Override]
    public function refusals(Command $command, Aggregates $aggregates): array
    {
        if ($aggregates->pending()) {
            return [];
        }

        return [new CatalogError(ErrorCode::ValidationFailed, new FieldPath('actor'), sprintf(
            'Only a pending actor is activated, but the actor %s %s (PRD 5.16).',
            $command->actor->toString(),
            $aggregates->current instanceof Actor ? 'is '.$aggregates->current->state->value : 'does not exist',
        ))];
    }

    /**
     * @param  ActivateActor  $command
     * @param  ActivateActorAggregates  $aggregates
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        return new Plan(new ActorActivated($command->actor));
    }
}
