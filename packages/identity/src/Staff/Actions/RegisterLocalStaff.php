<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Staff\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Envelope\UnitOfWork;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorDirectory;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\BreachedPasswordsUnavailable;
use Cbox\Cms\Contracts\Identity\LocalAccount;
use Cbox\Cms\Contracts\Identity\LocalCredentialStore;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\Identity\PasswordHash;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Identity\Domain\Commands\ActivateActor;
use Cbox\Cms\Core\Maintenance\Actions\RunMaintenanceCommand;
use Cbox\Cms\Core\Maintenance\Domain\Dto\MaintenanceCall;
use Cbox\Cms\Core\Operations\Domain\ChunkName;
use Cbox\Cms\Core\Operations\Domain\Dto\OperationProgress;
use Cbox\Cms\Core\Operations\Domain\Dto\OperationRequest;
use Cbox\Cms\Core\Operations\Domain\OperationKey;
use Cbox\Cms\Core\Operations\Domain\OperationKind;
use Cbox\Cms\Core\Operations\Domain\OperationRunner;
use Cbox\Cms\Core\Operations\Domain\OperationState;
use Cbox\Cms\Identity\LocalAccounts\Domain\PasswordHasher;
use Cbox\Cms\Identity\LocalAccounts\Domain\PasswordPolicy;
use Cbox\Cms\Identity\LocalAccounts\Domain\PasswordRefused;
use Cbox\Cms\Identity\Staff\Domain\Dto\RegisteredStaff;
use Cbox\Cms\Identity\Staff\Domain\Dto\StaffRegistration;
use Cbox\Cms\Identity\Staff\Domain\StaffRegistrationRefused;
use LogicException;

/**
 * Registers a local staff member (PRD 5.16, "Lokale konti") in the fixed order: actor.register
 * makes the actor pending, the credential is bound to the actor's id, and actor.activate makes the
 * actor active. Both commands run as the installation operator through RunMaintenanceCommand, so
 * they are changesets of the maintenance issuer with their audit, in that order.
 *
 * Before anything is written it refuses an email address whose login, the address in lower case,
 * has a local account (local_account_exists), and a password the PasswordPolicy refuses
 * (password_too_short, password_too_long, password_breached); when the breach check cannot be
 * made, BreachedPasswordsUnavailable goes to the caller. It hashes the password before
 * actor.register, so nothing after the first step but the store and the pipeline can fail.
 *
 * The three steps are two changesets and a write to the credential store, so they run as an
 * operation (GUARDRAILS 4.2) through the OperationRunner, StaffRegistrationChunks keyed by the
 * login: the actor's id comes from the IdGenerator once, when the operation starts, and the chunks
 * name it. A registration that stopped after actor.register, because a command was rejected, a
 * login was bound meanwhile (local_account_exists, with the actor as $pending) or the store or the
 * process failed, leaves the actor pending with no active login and the operation running. A rerun
 * for the same login resumes it with the same actor at the step that did not complete (the
 * account check passes when the login is bound to that actor), so one login never gets a second
 * actor. The rerun's password is the one the account ends with: it is bound when the bind had not
 * completed, and set with changePassword() when the login is bound to that actor while the
 * ActorDirectory reads it as a pending member of staff. The rerun's display name is not used once
 * actor.register committed, because the actor has its profile.
 *
 * A login bound to a pending member of staff with no registration of it running, as a
 * registration made before registrations were operations leaves it, is resumed too, so the email
 * is never locked out of local login by a registration that stopped: the account gets the rerun's
 * password and actor.activate runs for that actor at the version read, under the unit
 * `staff:<that actor id>`. A login whose actor is not a pending member of staff and not the actor
 * of the running registration, such as one with a completed registration (a local account is never
 * removed), is refused with local_account_exists before anything is written. An operation never
 * runs in an HTTP request, so this runs from the console (cms:staff:create).
 */
#[Internal]
final readonly class RegisterLocalStaff
{
    public function __construct(
        private PasswordPolicy $policy,
        private PasswordHasher $hasher,
        private LocalCredentialStore $store,
        private RunMaintenanceCommand $maintenance,
        private IdGenerator $ids,
        private OperationRunner $operations,
        private ActorDirectory $actors,
    ) {}

    /**
     * @throws StaffRegistrationRefused
     * @throws BreachedPasswordsUnavailable
     */
    public function register(StaffRegistration $registration): RegisteredStaff
    {
        $login = LoginIdentifier::fromEmail($registration->email);
        $key = StaffRegistrationChunks::keyOf($login);
        $resumed = $this->resumed($key);
        $bound = $this->store->find($login);
        $stopped = $bound instanceof LocalAccount ? $this->pending($bound) : null;
        $ofRun = $bound instanceof LocalAccount && $resumed instanceof ActorId && $bound->actor->equals($resumed);

        if ($bound instanceof LocalAccount && ! $ofRun && ! $stopped instanceof Actor) {
            throw StaffRegistrationRefused::loginTaken();
        }

        try {
            $this->policy->check($registration->password);
        } catch (PasswordRefused $refused) {
            throw StaffRegistrationRefused::password($refused);
        }

        $hash = $this->hasher->hash($registration->password);

        if ($stopped instanceof Actor && ! $ofRun) {
            return $this->activate($stopped, $hash);
        }

        if ($stopped instanceof Actor) {
            $this->store->changePassword($stopped->id, $hash);
        }

        $chunks = new StaffRegistrationChunks(
            $registration,
            $login,
            $hash,
            $resumed ?? new ActorId($this->ids->next()),
            $this->store,
            $this->maintenance,
        );
        $progress = $this->operations->run(new OperationRequest($chunks, $key));
        $last = $progress->completed[count($progress->completed) - 1] ?? null;

        if (! $last instanceof ChunkName) {
            throw new LogicException('A completed staff registration has completed its chunks.');
        }

        return new RegisteredStaff(StaffRegistrationChunks::actorOf($last));
    }

    /**
     * The actor of an account when it is a member of staff that is still pending, the trace of a
     * registration that stopped after its credential was bound; null for any other.
     */
    private function pending(LocalAccount $account): ?Actor
    {
        $actor = $this->actors->find($account->actor);

        return $actor instanceof Actor && $actor->class === ActorClass::Staff && $actor->state === ActorState::Pending ? $actor : null;
    }

    /**
     * Finishes a registration that stopped after its credential was bound and has no operation
     * running: the account gets the new password, and actor.activate runs at the version read,
     * under the actor's own unit of work.
     *
     * @throws StaffRegistrationRefused when actor.activate did not commit
     */
    private function activate(Actor $actor, PasswordHash $hash): RegisteredStaff
    {
        $this->store->changePassword($actor->id, $hash);

        $result = $this->maintenance->run(new MaintenanceCall(
            new ActivateActor($actor->id, new AggregateVersion($actor->version)),
            new UnitOfWork(StaffRegistrationChunks::UNIT_PREFIX.$actor->id->toString()),
        ));

        if ($result->outcome() !== Outcome::Committed && $result->outcome() !== Outcome::CommittedWaitTimeout) {
            throw StaffRegistrationRefused::rejected($result->errors[0], $actor->id);
        }

        return new RegisteredStaff($actor->id);
    }

    /**
     * The actor of the registration of the login that is running, which a run resumes; null when
     * none is.
     */
    private function resumed(OperationKey $key): ?ActorId
    {
        $progress = $this->operations->find(new OperationKind(StaffRegistrationChunks::KIND), $key);

        if (! $progress instanceof OperationProgress || $progress->state !== OperationState::Running) {
            return null;
        }

        $chunk = $progress->completed[0] ?? $progress->remaining[0] ?? null;

        return $chunk instanceof ChunkName ? StaffRegistrationChunks::actorOf($chunk) : null;
    }
}
