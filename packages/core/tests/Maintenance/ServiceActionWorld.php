<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Maintenance;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Idempotency\WaitBudget;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorDirectory;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\CredentialGeneration;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Mutations\ActorActivated;
use Cbox\Cms\Contracts\Plans\Mutations\ActorRegistered;
use Cbox\Cms\Core\IdempotencyStore\Domain\Dto\IdempotencySettings;
use Cbox\Cms\Core\Identity\Actions\ActivateActorAction;
use Cbox\Cms\Core\Identity\Actions\RegisterActorAction;
use Cbox\Cms\Core\Identity\Domain\Commands\ActivateActor;
use Cbox\Cms\Core\Identity\Domain\Commands\RegisterActor;
use Cbox\Cms\Core\Maintenance\Actions\IssueServiceCredential;
use Cbox\Cms\Core\Maintenance\Actions\RegisterServiceActor;
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
use Cbox\Cms\Core\Pipeline\Domain\Dto\StaleRead;
use Cbox\Cms\Core\Pipeline\Domain\Dto\VersionConflict;
use Cbox\Cms\Core\Pipeline\Domain\Dto\WaitSettings;
use Cbox\Cms\Core\Pipeline\Domain\HookPlans;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeAccessContexts;
use Cbox\Cms\Core\Tests\Maintenance\Fakes\FakeInstallationOperator;
use Cbox\Cms\Core\Tests\Maintenance\Fakes\FakeServiceCredentialIssuer;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeChangesetCommitter;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandContentHasher;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandHooks;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandTransaction;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeFieldValidation;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeHookOverruns;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakePublicPlacements;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeRevisionContents;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeStopwatch;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeWriteActions;
use Cbox\Cms\Core\Tests\Subscriptions\Fakes\FakePacing;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Idempotency\FakeIdempotencyStore;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\ReceiptStore\FakeReceiptStore;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use Cbox\Cms\Testkit\Validation\FakeTypeValidators;
use DateTimeImmutable;
use LogicException;
use Override;

/**
 * RegisterServiceActor and IssueServiceCredential over fakes (GUARDRAILS 9): the real
 * RunMaintenanceCommand and command pipeline with the MaintenanceAuthorizer, the core's actor
 * actions and the fakes of the pipeline's ports, an actor register that the commits change as the
 * Postgres writers do (actor.register adds the actor pending at version 1, actor.activate makes
 * it active at version 2), with an installed operator and an active staff member to be
 * responsible, and the FakeServiceCredentialIssuer, which verifies what it issued. Nothing
 * touches a database.
 */
final class ServiceActionWorld implements ActorDirectory, ChangesetCommitter
{
    public const string NOW = '2026-03-10T12:00:00Z';

    public readonly FakeClock $clock;

    public readonly ActorId $operator;

    public readonly ActorId $staff;

    public readonly FakeChangesetCommitter $committer;

    public readonly FakeServiceCredentialIssuer $issuer;

    /** How many of the next commits of actor.activate end in a version conflict instead, as a change of the actor meanwhile would make them. */
    public int $failingActivations = 0;

    private readonly FakeIdempotencyStore $keys;

    private readonly FakeReceiptStore $receipts;

    private readonly FakeIdGenerator $ids;

    /** @var array<string, Actor> by actor id */
    private array $actors = [];

    public function __construct(private readonly bool $installed = true)
    {
        $this->clock = new FakeClock(new DateTimeImmutable(self::NOW));
        $operator = new Actor(ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000a1'), ActorClass::Service, ActorState::Active, 2, CredentialGeneration::first());
        $staff = new Actor(ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000a2'), ActorClass::Staff, ActorState::Active, 2, CredentialGeneration::first());
        $this->actors[$operator->id->toString()] = $operator;
        $this->actors[$staff->id->toString()] = $staff;
        $this->operator = $operator->id;
        $this->staff = $staff->id;
        $this->committer = new FakeChangesetCommitter(ids: new FakeIdGenerator(clock: $this->clock));
        $this->issuer = new FakeServiceCredentialIssuer($this, $this->clock);
        $this->keys = new FakeIdempotencyStore($this->clock);
        $this->receipts = new FakeReceiptStore($this->clock);
        $this->ids = new FakeIdGenerator(seed: 7, clock: $this->clock);
    }

    public function registration(): RegisterServiceActor
    {
        return new RegisterServiceActor(new RunMaintenanceCommand(new FakeInstallationOperator($this->installed ? $this->operator : null), new FakeAccessContexts, new FakeIdGenerator(clock: $this->clock), $this->pipeline()), $this->ids);
    }

    public function issue(?Clock $clock = null): IssueServiceCredential
    {
        return new IssueServiceCredential($this, $this->issuer, $clock ?? $this->clock);
    }

    /**
     * Puts an actor in the register as a test needs it.
     */
    public function add(Actor $actor): Actor
    {
        $this->actors[$actor->id->toString()] = $actor;

        return $actor;
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
    public function find(ActorId $id): ?Actor
    {
        return $this->actors[$id->toString()] ?? null;
    }

    #[Override]
    public function commit(PendingChangeset $changeset): CommitOutcome
    {
        foreach ($changeset->plan->mutations() as $mutation) {
            if ($mutation instanceof ActorActivated && $this->failingActivations > 0) {
                $this->failingActivations--;

                return new VersionConflict(new StaleRead($mutation->actor, AggregateVersion::first(), new AggregateVersion(Actor::FIRST_VERSION + 1)));
            }
        }

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

    private function pipeline(): CommandPipeline
    {
        $installation = new FakeInstallationOperator($this->installed ? $this->operator : null);
        $keys = $this->keys->session();
        $receipts = $this->receipts->session();
        $types = new FakeTypeCatalog;

        return new CommandPipeline(
            new FakeWriteActions([
                RegisterActor::class => $this->binding('actor.register', new RegisterActorAction($this)),
                ActivateActor::class => $this->binding('actor.activate', new ActivateActorAction($this)),
            ]),
            $this,
            new MaintenanceAuthorizer($installation),
            $types,
            new FakeFieldValidation(new FakeTypeValidators),
            new FakeRevisionContents,
            new FakePublicPlacements,
            $this,
            $keys,
            $receipts,
            new FakeCommandContentHasher,
            new IdempotencySettings(WaitBudget::milliseconds(40)),
            new FakeCommandTransaction($keys, $receipts),
            new HookRunner(new FakeCommandHooks, new HookPlans($types), new FakeStopwatch, new FakeHookOverruns),
            new PipelineTelemetry(new FakeTelemetry, $this->clock, new FakeStopwatch),
            new AwaitWaitLevel($receipts, new FakePacing, new WaitSettings(0)),
        );
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
