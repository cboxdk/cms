<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Staff;

use Cbox\Cms\Contracts\Idempotency\WaitBudget;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorDirectory;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\CredentialGeneration;
use Cbox\Cms\Contracts\Identity\LocalCredentialStore;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Mutations\ActorActivated;
use Cbox\Cms\Contracts\Plans\Mutations\ActorRegistered;
use Cbox\Cms\Core\IdempotencyStore\Domain\Dto\IdempotencySettings;
use Cbox\Cms\Core\Identity\Actions\ActivateActorAction;
use Cbox\Cms\Core\Identity\Actions\RegisterActorAction;
use Cbox\Cms\Core\Identity\Domain\Commands\ActivateActor;
use Cbox\Cms\Core\Identity\Domain\Commands\RegisterActor;
use Cbox\Cms\Core\Maintenance\Actions\RunMaintenanceCommand;
use Cbox\Cms\Core\Maintenance\Domain\MaintenanceAuthorizer;
use Cbox\Cms\Core\Pipeline\Actions\AwaitWaitLevel;
use Cbox\Cms\Core\Pipeline\Actions\CommandPipeline;
use Cbox\Cms\Core\Pipeline\Actions\HookRunner;
use Cbox\Cms\Core\Pipeline\Domain\ChangesetCommitter;
use Cbox\Cms\Core\Pipeline\Domain\CommitOutcome;
use Cbox\Cms\Core\Pipeline\Domain\Dto\ActionBinding;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Committed;
use Cbox\Cms\Core\Pipeline\Domain\Dto\PendingChangeset;
use Cbox\Cms\Core\Pipeline\Domain\Dto\WaitSettings;
use Cbox\Cms\Core\Pipeline\Domain\HookPlans;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeAccessContexts;
use Cbox\Cms\Core\Tests\Maintenance\Fakes\FakeInstallationOperator;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeChangesetCommitter;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandContentHasher;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandHooks;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandTransaction;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeFieldValidation;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeHookOverruns;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeRevisionContents;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeStopwatch;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeWriteActions;
use Cbox\Cms\Core\Tests\Subscriptions\Fakes\FakePacing;
use Cbox\Cms\Identity\LocalAccounts\Domain\PasswordPolicy;
use Cbox\Cms\Identity\Staff\Actions\RegisterLocalStaff;
use Cbox\Cms\Identity\Tests\LocalAccounts\CountingPasswordHasher;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Idempotency\FakeIdempotencyStore;
use Cbox\Cms\Testkit\Identity\FakeBreachedPasswords;
use Cbox\Cms\Testkit\Identity\FakeLocalCredentialStore;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\ReceiptStore\FakeReceiptStore;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use Cbox\Cms\Testkit\Validation\FakeTypeValidators;
use LogicException;
use Override;

/**
 * RegisterLocalStaff over fakes (GUARDRAILS 9): the real RunMaintenanceCommand and command pipeline
 * with the MaintenanceAuthorizer, the core's actor actions and the fakes of the pipeline's ports,
 * an actor register that the commits change as the Postgres writers do (actor.register adds the
 * actor pending at version 1, actor.activate makes it active at version 2), the testkit's
 * FakeLocalCredentialStore bound to that register, the real password policy over
 * FakeBreachedPasswords and the real hasher at cheap parameters. Nothing touches a database.
 */
final class StaffWorld implements ActorDirectory, ChangesetCommitter
{
    public const string BREACHED = 'Summer2026!Summer';

    public readonly FakeClock $clock;

    public readonly ActorId $operator;

    public readonly FakeLocalCredentialStore $accounts;

    public readonly FakeBreachedPasswords $breached;

    public readonly FakeTelemetry $telemetry;

    public readonly FakeChangesetCommitter $committer;

    /** @var array<string, Actor> by actor id */
    private array $actors = [];

    public function __construct(private readonly bool $installed = true)
    {
        $this->clock = new FakeClock;
        $operator = new Actor(ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000a1'), ActorClass::Service, ActorState::Active, 2, CredentialGeneration::first());
        $this->actors[$operator->id->toString()] = $operator;
        $this->operator = $operator->id;
        $this->accounts = new FakeLocalCredentialStore($this->clock, $this);
        $this->breached = new FakeBreachedPasswords(new Password(self::BREACHED));
        $this->telemetry = new FakeTelemetry;
        $this->committer = new FakeChangesetCommitter(ids: new FakeIdGenerator(clock: $this->clock));
    }

    /**
     * The action, with the store a test gives in place of the fake, such as one whose writes fail.
     */
    public function action(?LocalCredentialStore $store = null): RegisterLocalStaff
    {
        $installation = new FakeInstallationOperator($this->installed ? $this->operator : null);
        $keys = new FakeIdempotencyStore($this->clock)->session();
        $receipts = new FakeReceiptStore($this->clock)->session();
        $types = new FakeTypeCatalog;
        $pipeline = new CommandPipeline(
            new FakeWriteActions([
                RegisterActor::class => $this->binding('actor.register', new RegisterActorAction($this)),
                ActivateActor::class => $this->binding('actor.activate', new ActivateActorAction($this)),
            ]),
            $this,
            new MaintenanceAuthorizer($installation),
            $types,
            new FakeFieldValidation(new FakeTypeValidators),
            new FakeRevisionContents,
            $this,
            $keys,
            $receipts,
            new FakeCommandContentHasher,
            new IdempotencySettings(WaitBudget::milliseconds(40)),
            new FakeCommandTransaction($keys, $receipts),
            new HookRunner(new FakeCommandHooks, new HookPlans($types), new FakeStopwatch, new FakeHookOverruns),
            new PipelineTelemetry($this->telemetry, $this->clock, new FakeStopwatch),
            new AwaitWaitLevel($receipts, new FakePacing, new WaitSettings(0)),
        );

        return new RegisterLocalStaff(
            new PasswordPolicy($this->breached),
            new CountingPasswordHasher,
            $store ?? $this->accounts,
            new RunMaintenanceCommand($installation, new FakeAccessContexts, new FakeIdGenerator(clock: $this->clock), $pipeline),
            new FakeIdGenerator(seed: 7, clock: $this->clock),
        );
    }

    #[Override]
    public function find(ActorId $id): ?Actor
    {
        return $this->actors[$id->toString()] ?? null;
    }

    /**
     * The commands the commits wrote, by name, in order.
     *
     * @return list<string>
     */
    public function committed(): array
    {
        return array_map(static fn (PendingChangeset $changeset): string => $changeset->command->value, $this->committer->pending);
    }

    #[Override]
    public function commit(PendingChangeset $changeset): CommitOutcome
    {
        $outcome = $this->committer->commit($changeset);

        if (! $outcome instanceof Committed) {
            return $outcome;
        }

        foreach ($changeset->plan->mutations() as $mutation) {
            if ($mutation instanceof ActorRegistered) {
                $this->actors[$mutation->actor->toString()] = new Actor($mutation->actor, $mutation->class, ActorState::Pending, Actor::FIRST_VERSION, CredentialGeneration::first());
            }

            if ($mutation instanceof ActorActivated && ($actor = $this->find($mutation->actor)) instanceof Actor) {
                $this->actors[$mutation->actor->toString()] = new Actor($actor->id, $actor->class, ActorState::Active, $actor->version + 1, $actor->credentialGeneration);
            }
        }

        return $outcome;
    }

    /**
     * The binding of a command, version 1, as the registry gives it: it takes any write action.
     */
    private function binding(string $command, object $action): ActionBinding
    {
        if (! $action instanceof WriteAction) {
            throw new LogicException(sprintf('%s is not a write action.', $action::class));
        }

        return new ActionBinding(new CommandName($command), 1, $action);
    }
}
