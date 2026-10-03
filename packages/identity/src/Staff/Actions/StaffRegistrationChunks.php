<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Staff\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Envelope\UnitOfWork;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\LocalAccount;
use Cbox\Cms\Contracts\Identity\LocalAccountExists;
use Cbox\Cms\Contracts\Identity\LocalCredentialStore;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\Identity\PasswordHash;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Identity\Domain\Commands\ActivateActor;
use Cbox\Cms\Core\Identity\Domain\Commands\RegisterActor;
use Cbox\Cms\Core\Maintenance\Actions\RunMaintenanceCommand;
use Cbox\Cms\Core\Maintenance\Domain\Dto\MaintenanceCall;
use Cbox\Cms\Core\Operations\Domain\ChunkedAction;
use Cbox\Cms\Core\Operations\Domain\ChunkName;
use Cbox\Cms\Core\Operations\Domain\ChunkPlan;
use Cbox\Cms\Core\Operations\Domain\InvalidOperation;
use Cbox\Cms\Core\Operations\Domain\OperationKey;
use Cbox\Cms\Core\Operations\Domain\OperationKind;
use Cbox\Cms\Identity\Staff\Domain\Dto\StaffRegistration;
use Cbox\Cms\Identity\Staff\Domain\StaffRegistrationRefused;
use Override;

/**
 * The steps of one local staff registration as an operation (PRD 5.16, GUARDRAILS 4.2): the chunks
 * register (actor.register, the actor pending), bind (the credential bound to the actor's id) and
 * activate (actor.activate), in that order, each naming the actor, `<step>:<actor id>`. The
 * operation is keyed by the login, as the SHA-256 of it so the operations table holds no email
 * address (keyOf()), and the plan is asked once, when the operation starts: a registration that
 * stopped, because a command was rejected, the store failed or the process died, resumes with the
 * same actor when it runs again for the same login, instead of registering another one.
 *
 * Each chunk is idempotent. Both commands run as the installation operator through
 * RunMaintenanceCommand with the unit of work `staff:<actor id>`, so a command that committed
 * replays its receipt; bind does nothing when the login is bound to this actor already, and a login
 * bound to another actor is refused with local_account_exists. A command that is rejected throws
 * StaffRegistrationRefused, with the actor as pending once actor.register committed.
 */
#[Internal]
final readonly class StaffRegistrationChunks implements ChunkedAction
{
    public const string KIND = 'identity.staff_registration';

    public const string UNIT_PREFIX = 'staff:';

    public const string KEY_PREFIX = 'staff-login:';

    public const string REGISTER = 'register';

    public const string BIND = 'bind';

    public const string ACTIVATE = 'activate';

    public function __construct(
        private StaffRegistration $registration,
        private LoginIdentifier $login,
        private PasswordHash $hash,
        private ActorId $actor,
        private LocalCredentialStore $store,
        private RunMaintenanceCommand $maintenance,
    ) {}

    /**
     * The operation key of a registration of the login: the SHA-256 of the login, so the key holds
     * no email address.
     */
    public static function keyOf(LoginIdentifier $login): OperationKey
    {
        return new OperationKey(self::KEY_PREFIX.hash('sha256', $login->value));
    }

    /**
     * The actor a chunk of a registration names.
     *
     * @throws InvalidOperation for a name that is not a chunk of a registration
     */
    public static function actorOf(ChunkName $chunk): ActorId
    {
        $parts = explode(':', $chunk->value, 2);

        if (count($parts) !== 2 || ! in_array($parts[0], [self::REGISTER, self::BIND, self::ACTIVATE], true)) {
            throw InvalidOperation::chunk($chunk->value);
        }

        return ActorId::fromString($parts[1]);
    }

    #[Override]
    public function kind(): OperationKind
    {
        return new OperationKind(self::KIND);
    }

    #[Override]
    public function chunks(): ChunkPlan
    {
        $actor = $this->actor->toString();

        return new ChunkPlan(
            new ChunkName(self::REGISTER.':'.$actor),
            new ChunkName(self::BIND.':'.$actor),
            new ChunkName(self::ACTIVATE.':'.$actor),
        );
    }

    /**
     * @throws StaffRegistrationRefused when a command is rejected or the login is bound to another actor
     * @throws InvalidOperation for a name that is not a chunk of a registration
     */
    #[Override]
    public function runChunk(ChunkName $chunk): void
    {
        $actor = self::actorOf($chunk);
        $unit = new UnitOfWork(self::UNIT_PREFIX.$actor->toString());

        match (explode(':', $chunk->value, 2)[0]) {
            self::REGISTER => $this->committed($this->maintenance->run(new MaintenanceCall(
                new RegisterActor($actor, ActorClass::Staff, $this->registration->name, $this->registration->email),
                $unit,
            )), null),
            self::BIND => $this->bind($actor),
            default => $this->committed($this->maintenance->run(new MaintenanceCall(
                new ActivateActor($actor, AggregateVersion::first()),
                $unit,
            )), $actor),
        };
    }

    /**
     * @throws StaffRegistrationRefused when the login is bound to another actor
     */
    private function bind(ActorId $actor): void
    {
        $bound = $this->store->find($this->login);

        if ($bound instanceof LocalAccount) {
            if ($bound->actor->equals($actor)) {
                return;
            }

            throw StaffRegistrationRefused::loginTaken($actor);
        }

        try {
            $this->store->bind($actor, $this->login, $this->hash);
        } catch (LocalAccountExists) {
            throw StaffRegistrationRefused::loginTaken($actor);
        }
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
