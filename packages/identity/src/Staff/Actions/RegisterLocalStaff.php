<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Staff\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Envelope\UnitOfWork;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\BreachedPasswordsUnavailable;
use Cbox\Cms\Contracts\Identity\LocalAccount;
use Cbox\Cms\Contracts\Identity\LocalAccountExists;
use Cbox\Cms\Contracts\Identity\LocalCredentialStore;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Identity\Domain\Commands\ActivateActor;
use Cbox\Cms\Core\Identity\Domain\Commands\RegisterActor;
use Cbox\Cms\Core\Maintenance\Actions\RunMaintenanceCommand;
use Cbox\Cms\Core\Maintenance\Domain\Dto\MaintenanceCall;
use Cbox\Cms\Identity\LocalAccounts\Domain\PasswordHasher;
use Cbox\Cms\Identity\LocalAccounts\Domain\PasswordPolicy;
use Cbox\Cms\Identity\LocalAccounts\Domain\PasswordRefused;
use Cbox\Cms\Identity\Staff\Domain\Dto\RegisteredStaff;
use Cbox\Cms\Identity\Staff\Domain\Dto\StaffRegistration;
use Cbox\Cms\Identity\Staff\Domain\StaffRegistrationRefused;

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
 * The actor's id comes from the IdGenerator, and both commands run with the unit of work
 * `staff:<actor id>`, so a rerun of the same registration replays. A failure after actor.register
 * leaves the actor pending and no active login: a rejected command, a login another registration
 * bound meanwhile (local_account_exists) and a rejected activation are refused with the actor as
 * $pending; anything else the store throws goes to the caller as it is. A pending actor never logs
 * in, and the kernel's job deprovisions actors pending for 24 hours (PRD 5.16).
 */
#[Internal]
final readonly class RegisterLocalStaff
{
    public const string UNIT_PREFIX = 'staff:';

    public function __construct(
        private PasswordPolicy $policy,
        private PasswordHasher $hasher,
        private LocalCredentialStore $store,
        private RunMaintenanceCommand $maintenance,
        private IdGenerator $ids,
    ) {}

    /**
     * @throws StaffRegistrationRefused
     * @throws BreachedPasswordsUnavailable
     */
    public function register(StaffRegistration $registration): RegisteredStaff
    {
        $login = LoginIdentifier::fromEmail($registration->email);

        if ($this->store->find($login) instanceof LocalAccount) {
            throw StaffRegistrationRefused::loginTaken();
        }

        try {
            $this->policy->check($registration->password);
        } catch (PasswordRefused $refused) {
            throw StaffRegistrationRefused::password($refused);
        }

        $hash = $this->hasher->hash($registration->password);
        $actor = new ActorId($this->ids->next());
        $unit = new UnitOfWork(self::UNIT_PREFIX.$actor->toString());

        $this->committed($this->maintenance->run(new MaintenanceCall(
            new RegisterActor($actor, ActorClass::Staff, $registration->name, $registration->email),
            $unit,
        )), null);

        try {
            $this->store->bind($actor, $login, $hash);
        } catch (LocalAccountExists) {
            throw StaffRegistrationRefused::loginTaken($actor);
        }

        $this->committed($this->maintenance->run(new MaintenanceCall(
            new ActivateActor($actor, AggregateVersion::first()),
            $unit,
        )), $actor);

        return new RegisteredStaff($actor);
    }

    /**
     * @throws StaffRegistrationRefused when the command did not commit
     */
    private function committed(WriteResult $result, ?ActorId $pending): void
    {
        if ($result->outcome() === Outcome::Committed || $result->outcome() === Outcome::CommittedWaitTimeout) {
            return;
        }

        throw StaffRegistrationRefused::rejected($result->errors[0], $pending);
    }
}
