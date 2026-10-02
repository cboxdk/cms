<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Identity;

use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Envelope\IssuerKind as EnvelopeIssuer;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Idempotency\WaitBudget;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\IdempotencyStore\Adapter\PostgresIdempotencyStore;
use Cbox\Cms\Core\IdempotencyStore\Domain\Dto\IdempotencySettings;
use Cbox\Cms\Core\Identity\Actions\ActivateActorAction;
use Cbox\Cms\Core\Identity\Actions\RegisterActorAction;
use Cbox\Cms\Core\Identity\Adapter\ActorActivatedWriter;
use Cbox\Cms\Core\Identity\Adapter\ActorRegisteredWriter;
use Cbox\Cms\Core\Identity\Adapter\PostgresActorDirectory;
use Cbox\Cms\Core\Identity\Adapter\PostgresActorVersionLock;
use Cbox\Cms\Core\Identity\Domain\Commands\ActivateActor;
use Cbox\Cms\Core\Identity\Domain\Commands\RegisterActor;
use Cbox\Cms\Core\Pipeline\Actions\AwaitWaitLevel;
use Cbox\Cms\Core\Pipeline\Actions\CommandPipeline;
use Cbox\Cms\Core\Pipeline\Actions\HookRunner;
use Cbox\Cms\Core\Pipeline\Adapter\ConnectionCommandTransaction;
use Cbox\Cms\Core\Pipeline\Adapter\PostgresChangesetCommitter;
use Cbox\Cms\Core\Pipeline\Adapter\SavepointRefusal;
use Cbox\Cms\Core\Pipeline\Domain\Dto\ActionBinding;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCall;
use Cbox\Cms\Core\Pipeline\Domain\Dto\WaitSettings;
use Cbox\Cms\Core\Pipeline\Domain\HookPlans;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriters;
use Cbox\Cms\Core\Pipeline\Domain\VersionLocks;
use Cbox\Cms\Core\ReceiptStore\Adapter\PostgresReceiptStore;
use Cbox\Cms\Core\Subscriptions\Adapter\SystemPacing;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeAffectedProjections;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandAuthorizer;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandContentHasher;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandHooks;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeFieldValidation;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeHookOverruns;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeRevisionContents;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeStopwatch;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeWriteActions;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use Cbox\Cms\Testkit\Validation\FakeTypeValidators;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use LogicException;

/**
 * The real command pipeline on Postgres for actor.register and actor.activate (PRD 5.16, 6.2): the
 * command transaction, the Postgres idempotency and receipt stores, the actor directory, the actions
 * and the PostgresChangesetCommitter with the actor's version lock and the writers of
 * ActorRegistered and ActorActivated, on the container's connections at NOW. Fakes, so a test
 * decides what they answer: the authorizer, which allows, the content hasher, the type catalog and
 * the hooks. The caller covers the partitions.
 */
final readonly class RegistrationWorld
{
    public const string NOW = '2026-03-10T12:00:00.000000+00:00';

    public FakeClock $clock;

    private ConnectionResolverInterface $connections;

    private FakeIdGenerator $ids;

    public function __construct()
    {
        $this->clock = new FakeClock(new DateTimeImmutable(self::NOW));
        $this->connections = app(ConnectionResolverInterface::class);
        $this->ids = new FakeIdGenerator(seed: 41, clock: $this->clock);
    }

    /**
     * Runs the command on the CLI as the actor $by, with the key given.
     */
    public function run(ActorId $by, RegisterActor|ActivateActor $command, string $key): WriteResult
    {
        $envelope = Envelope::external(
            IssuingSurface::Cli,
            EnvelopeIssuer::Human,
            $by,
            new IdempotencyKey($key),
            new CorrelationId('registration-correlation'),
        );

        return $this->pipeline()->run(new CommandCall($command, $envelope, new AccessContext(
            new ActorPrincipal($by, [], IssuerKind::Service, ClassificationAccess::Sensitive),
            [],
            ClassificationAccess::Personal,
        )));
    }

    private function pipeline(): CommandPipeline
    {
        $types = new FakeTypeCatalog;
        $directory = new PostgresActorDirectory($this->connections);
        $receipts = new PostgresReceiptStore($this->connections, $this->clock);

        return new CommandPipeline(
            new FakeWriteActions([
                RegisterActor::class => $this->binding('actor.register', new RegisterActorAction($directory)),
                ActivateActor::class => $this->binding('actor.activate', new ActivateActorAction($directory)),
            ]),
            $directory,
            new FakeCommandAuthorizer,
            $types,
            new FakeFieldValidation(new FakeTypeValidators),
            new FakeRevisionContents,
            new PostgresChangesetCommitter(
                $this->connections,
                $this->clock,
                $this->ids,
                new VersionLocks(new PostgresActorVersionLock($this->connections)),
                new MutationWriters(new ActorRegisteredWriter($this->connections), new ActorActivatedWriter($this->connections)),
                new FakeAffectedProjections,
                $receipts,
            ),
            new PostgresIdempotencyStore($this->connections, $this->clock),
            $receipts,
            new FakeCommandContentHasher,
            new IdempotencySettings(WaitBudget::milliseconds(200)),
            new ConnectionCommandTransaction($this->connections, new SavepointRefusal),
            new HookRunner(new FakeCommandHooks, new HookPlans($types), new FakeStopwatch, new FakeHookOverruns),
            new PipelineTelemetry(new FakeTelemetry, new FakeClock, new FakeStopwatch),
            new AwaitWaitLevel($receipts, new SystemPacing, new WaitSettings(0)),
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
