<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Staff\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\BreachedPasswordsUnavailable;
use Cbox\Cms\Contracts\Identity\LocalAccount;
use Cbox\Cms\Contracts\Identity\LocalCredentialStore;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Core\Maintenance\Actions\RunMaintenanceCommand;
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
 * account check passes when the login is bound to that actor, and the rerun's password is bound
 * when the bind had not completed), so one login never gets a second actor. A completed
 * registration leaves the login bound, and a local account is never removed, so a login with a
 * completed registration is refused before the runner is asked. An operation never runs in an HTTP
 * request, so this runs from the console (cms:staff:create).
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

        if ($bound instanceof LocalAccount && (! $resumed instanceof ActorId || ! $bound->actor->equals($resumed))) {
            throw StaffRegistrationRefused::loginTaken();
        }

        try {
            $this->policy->check($registration->password);
        } catch (PasswordRefused $refused) {
            throw StaffRegistrationRefused::password($refused);
        }

        $chunks = new StaffRegistrationChunks(
            $registration,
            $login,
            $this->hasher->hash($registration->password),
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
