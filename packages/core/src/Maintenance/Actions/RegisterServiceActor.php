<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Maintenance\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Envelope\UnitOfWork;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Identity\Domain\Commands\ActivateActor;
use Cbox\Cms\Core\Identity\Domain\Commands\RegisterActor;
use Cbox\Cms\Core\Maintenance\Domain\Dto\MaintenanceCall;
use Cbox\Cms\Core\Maintenance\Domain\Dto\RegisteredService;
use Cbox\Cms\Core\Maintenance\Domain\Dto\ServiceRegistration;
use Cbox\Cms\Core\Maintenance\Domain\ServiceRegistrationRefused;

/**
 * Registers a service actor (PRD 5.16), the action behind cms:service:create: actor.register makes
 * the actor pending, with its profile and the active staff actor responsible for it, and
 * actor.activate makes it active, both as the installation operator through RunMaintenanceCommand,
 * so they are two changesets of the maintenance issuer with their audit, in that order. The
 * actor's id comes from the IdGenerator once, and both commands run under the unit of work
 * `service:<actor id>`.
 *
 * A service actor has no login: it acts through a service credential, which cms:credential:issue
 * issues to it once it is active (IssueServiceCredential). A command the pipeline rejects stops the
 * registration with ServiceRegistrationRefused: before anything is written when actor.register is
 * rejected, such as installation_operator_missing before cms:install or validation_failed for a
 * responsible person that is not an active staff actor; with the actor pending when
 * actor.activate is rejected, which only a change of the actor meanwhile makes it, and a rerun
 * registers another actor. Two commands take well under a second, so the registration is not an
 * operation (GUARDRAILS 4.2).
 */
#[Internal]
final readonly class RegisterServiceActor
{
    public const string UNIT_PREFIX = 'service:';

    public function __construct(
        private RunMaintenanceCommand $maintenance,
        private IdGenerator $ids,
    ) {}

    /**
     * @throws ServiceRegistrationRefused when a command did not commit
     */
    public function register(ServiceRegistration $registration): RegisteredService
    {
        $actor = new ActorId($this->ids->next());
        $unit = new UnitOfWork(self::UNIT_PREFIX.$actor->toString());

        $registered = $this->committed($this->maintenance->run(new MaintenanceCall(
            new RegisterActor($actor, ActorClass::Service, $registration->displayName, $registration->email, $registration->responsible),
            $unit,
        )), null);
        $activated = $this->committed($this->maintenance->run(new MaintenanceCall(
            new ActivateActor($actor, AggregateVersion::first()),
            $unit,
        )), $actor);

        return new RegisteredService($actor, $registered, $activated);
    }

    /**
     * @throws ServiceRegistrationRefused when the command did not commit
     */
    private function committed(WriteResult $result, ?ActorId $pending): ChangesetId
    {
        $changeset = $result->receipt->changesetId;

        if (($result->outcome() === Outcome::Committed || $result->outcome() === Outcome::CommittedWaitTimeout) && $changeset instanceof ChangesetId) {
            return $changeset;
        }

        throw ServiceRegistrationRefused::rejected($result->errors[0], $pending);
    }
}
