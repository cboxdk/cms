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
use Cbox\Cms\Contracts\Identity\DeactivationSource;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\IdempotencyStore\Adapter\PostgresIdempotencyStore;
use Cbox\Cms\Core\IdempotencyStore\Domain\Dto\IdempotencySettings;
use Cbox\Cms\Core\Identity\Actions\DeactivateActorAction;
use Cbox\Cms\Core\Identity\Adapter\ActorDeactivatedWriter;
use Cbox\Cms\Core\Identity\Adapter\PostgresActorDirectory;
use Cbox\Cms\Core\Identity\Adapter\PostgresActorVersionLock;
use Cbox\Cms\Core\Identity\Domain\Commands\DeactivateActor;
use Cbox\Cms\Core\Pipeline\Actions\CommandPipeline;
use Cbox\Cms\Core\Pipeline\Actions\HookRunner;
use Cbox\Cms\Core\Pipeline\Adapter\ConnectionCommandTransaction;
use Cbox\Cms\Core\Pipeline\Adapter\PostgresChangesetCommitter;
use Cbox\Cms\Core\Pipeline\Adapter\SavepointRefusal;
use Cbox\Cms\Core\Pipeline\Domain\ChangesetCommitter;
use Cbox\Cms\Core\Pipeline\Domain\CommandAuthorizer;
use Cbox\Cms\Core\Pipeline\Domain\Dto\ActionBinding;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCall;
use Cbox\Cms\Core\Pipeline\Domain\HookPlans;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriters;
use Cbox\Cms\Core\Pipeline\Domain\VersionLocks;
use Cbox\Cms\Core\ReceiptStore\Adapter\PostgresReceiptStore;
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
use Closure;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\PostgresConnection;
use LogicException;

/**
 * The real command pipeline on Postgres for actor.deactivate (PRD 5.16, 6.2): the command
 * transaction, the Postgres idempotency and receipt stores, the actor directory, the action and the
 * PostgresChangesetCommitter with the actor's version lock and the writer of ActorDeactivated, all
 * on the connections given. Only what the kernel has no real implementation of yet is a fake: the
 * authorizer, which allows unless a test gives another, the content hasher, the type catalog and
 * the hooks.
 *
 * It needs no container, so a child process builds it on its own connection (onConnection()); the
 * clock stands at NOW there too, and each world's ids come from a seed of its own. A world can hold
 * its transaction open after the commit wrote everything and before the command transaction commits
 * it: afterCommit runs there, as another session would wait meanwhile.
 */
final readonly class DeactivationWorld
{
    public const string NOW = '2026-03-10T12:00:00.000000+00:00';

    public const string COMMAND = 'actor.deactivate';

    /**
     * @param  (Closure(): void)|null  $afterCommit
     */
    private function __construct(private ConnectionResolverInterface $connections, private FakeIdGenerator $ids, private CommandAuthorizer $authorizer, private ?Closure $afterCommit, public FakeClock $clock) {}

    /**
     * On the container's connections, at NOW; the caller covers the partitions.
     */
    public static function onDefault(CommandAuthorizer $authorizer = new FakeCommandAuthorizer, int $seed = 32): self
    {
        $clock = new FakeClock(new DateTimeImmutable(self::NOW));

        return new self(app(ConnectionResolverInterface::class), new FakeIdGenerator(seed: $seed, clock: $clock), $authorizer, null, $clock);
    }

    /**
     * On the one connection given, which becomes the default of the world's own resolver, at NOW.
     *
     * @param  (Closure(): void)|null  $afterCommit
     */
    public static function onConnection(PostgresConnection $connection, int $seed, ?Closure $afterCommit = null): self
    {
        $clock = new FakeClock(new DateTimeImmutable(self::NOW));
        $name = 'deactivation';
        $connections = new ConnectionResolver([$name => $connection]);
        $connections->setDefaultConnection($name);

        return new self($connections, new FakeIdGenerator(seed: $seed, clock: $clock), new FakeCommandAuthorizer, $afterCommit, $clock);
    }

    public function pipeline(): CommandPipeline
    {
        $types = new FakeTypeCatalog;
        $directory = new PostgresActorDirectory($this->connections);

        return new CommandPipeline(
            new FakeWriteActions([DeactivateActor::class => self::binding(new DeactivateActorAction($directory))]),
            $directory,
            $this->authorizer,
            $types,
            new FakeFieldValidation(new FakeTypeValidators),
            new FakeRevisionContents,
            $this->committer(),
            new PostgresIdempotencyStore($this->connections, $this->clock),
            new PostgresReceiptStore($this->connections, $this->clock),
            new FakeCommandContentHasher,
            new IdempotencySettings(WaitBudget::milliseconds(200)),
            new ConnectionCommandTransaction($this->connections, new SavepointRefusal),
            new HookRunner(new FakeCommandHooks, new HookPlans($types), new FakeStopwatch, new FakeHookOverruns),
            new PipelineTelemetry(new FakeTelemetry, new FakeClock, new FakeStopwatch),
        );
    }

    /**
     * Runs actor.deactivate through REST as the actor $by, on the target, with the key given.
     */
    public function deactivate(ActorId $by, ActorId $target, string $key = 'deactivate-1', DeactivationSource $source = DeactivationSource::Local, bool $dryRun = false): WriteResult
    {
        $envelope = Envelope::external(
            IssuingSurface::Rest,
            EnvelopeIssuer::Human,
            $by,
            new IdempotencyKey($key),
            new CorrelationId('deactivate-correlation'),
            dryRun: $dryRun,
        );

        return $this->pipeline()->run(new CommandCall(new DeactivateActor($target, $source), $envelope, new AccessContext(
            new ActorPrincipal($by, [], IssuerKind::Service, ClassificationAccess::Sensitive),
            [],
            ClassificationAccess::Internal,
        )));
    }

    /**
     * The binding of actor.deactivate, version 1, as the registry gives it: it takes any write action.
     */
    public static function binding(object $action): ActionBinding
    {
        if (! $action instanceof WriteAction) {
            throw new LogicException(sprintf('%s is not a write action.', $action::class));
        }

        return new ActionBinding(new CommandName(self::COMMAND), 1, $action);
    }

    private function committer(): ChangesetCommitter
    {
        $committer = new PostgresChangesetCommitter(
            $this->connections,
            $this->clock,
            $this->ids,
            new VersionLocks(new PostgresActorVersionLock($this->connections)),
            new MutationWriters(new ActorDeactivatedWriter($this->connections)),
            new FakeAffectedProjections,
            new PostgresReceiptStore($this->connections, $this->clock),
        );

        return $this->afterCommit instanceof Closure ? new HoldingCommitter($committer, $this->afterCommit) : $committer;
    }
}
