<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Identity\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorDirectory;
use Cbox\Cms\Contracts\Identity\ActorProfile;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\RefusesCommand;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Mutations\ActorRegistered;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Identity\Domain\Commands\RegisterActor;
use Cbox\Cms\Core\Identity\Domain\Dto\RegisterActorAggregates;
use Override;

/**
 * The write action of actor.register (PRD 5.16, 6.2). resolve() reads the actor through the
 * ActorDirectory, which the command expects absent, and the person responsible for it when the
 * command names one. refusals() refuses, with validation_failed, an end user, whose connections
 * and policy come with block B13; a service actor without a responsible person, or with one that is
 * not an active staff actor; and a staff actor with one. plan() registers the actor with its
 * profile.
 *
 * It is exposed on the CLI alone. A registration on the REST API or the panel would leave a
 * pending actor behind whenever the steps after it fail, and the job that deprovisions actors
 * pending for 24 hours comes with block B6; the panel's registration comes with the local login.
 *
 * @implements WriteAction<RegisterActor, RegisterActorAggregates>
 * @implements RefusesCommand<RegisterActor, RegisterActorAggregates>
 */
#[Action(handles: RegisterActor::class, surfaces: [Surface::Cli])]
#[Internal]
final readonly class RegisterActorAction implements RefusesCommand, WriteAction
{
    public function __construct(private ActorDirectory $actors) {}

    /**
     * @param  RegisterActor  $command
     */
    #[Override]
    public function resolve(Command $command): RegisterActorAggregates
    {
        $responsible = $command->responsible;

        return new RegisterActorAggregates(
            $command->actor,
            $this->actors->find($command->actor),
            $responsible,
            $responsible instanceof ActorId ? $this->actors->find($responsible) : null,
        );
    }

    /**
     * @param  RegisterActor  $command
     * @param  RegisterActorAggregates  $aggregates
     */
    #[Override]
    public function refusals(Command $command, Aggregates $aggregates): array
    {
        $responsible = new FieldPath('responsible');

        return match ($command->class) {
            ActorClass::EndUser => [$this->invalid(new FieldPath('class'), 'An end user is not registered with actor.register: the end users\' connections and login policy come with block B13 (PRD 5.16, 15.1). Register staff or service.')],
            ActorClass::Staff => $command->responsible instanceof ActorId
                ? [$this->invalid($responsible, 'A staff actor has no responsible person; only a service actor has one (PRD 5.16).')]
                : [],
            ActorClass::Service => match (true) {
                ! $command->responsible instanceof ActorId => [$this->invalid($responsible, 'A service actor is registered with the active staff actor responsible for it (PRD 5.16).')],
                ! $aggregates->responsibleIsActiveStaff() => [$this->invalid($responsible, sprintf(
                    'The responsible person %s is %s; a service actor\'s responsible person is an active staff actor (PRD 5.16).',
                    $command->responsible->toString(),
                    $this->described($aggregates->responsible),
                ))],
                default => [],
            },
        };
    }

    /**
     * @param  RegisterActor  $command
     * @param  RegisterActorAggregates  $aggregates
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        return new Plan(new ActorRegistered(
            $command->actor,
            $command->class,
            new ActorProfile($command->displayName, $command->email),
            $command->responsible,
        ));
    }

    private function described(?Actor $actor): string
    {
        return $actor instanceof Actor
            ? sprintf('a %s actor that is %s', $actor->class->value, $actor->state->value)
            : 'no actor';
    }

    private function invalid(FieldPath $path, string $message): CatalogError
    {
        return new CatalogError(ErrorCode::ValidationFailed, $path, $message);
    }
}
