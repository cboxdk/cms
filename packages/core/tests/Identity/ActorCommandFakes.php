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
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\IdempotencyStore\Domain\Dto\IdempotencySettings;
use Cbox\Cms\Core\Identity\Actions\ActivateActorAction;
use Cbox\Cms\Core\Identity\Actions\DeactivateActorAction;
use Cbox\Cms\Core\Identity\Actions\RegisterActorAction;
use Cbox\Cms\Core\Identity\Domain\Commands\ActivateActor;
use Cbox\Cms\Core\Identity\Domain\Commands\DeactivateActor;
use Cbox\Cms\Core\Identity\Domain\Commands\RegisterActor;
use Cbox\Cms\Core\Pipeline\Actions\AwaitWaitLevel;
use Cbox\Cms\Core\Pipeline\Actions\CommandPipeline;
use Cbox\Cms\Core\Pipeline\Actions\HookRunner;
use Cbox\Cms\Core\Pipeline\Domain\CommandAuthorizer;
use Cbox\Cms\Core\Pipeline\Domain\CommitOutcome;
use Cbox\Cms\Core\Pipeline\Domain\Dto\ActionBinding;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCall;
use Cbox\Cms\Core\Pipeline\Domain\Dto\WaitSettings;
use Cbox\Cms\Core\Pipeline\Domain\HookPlans;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeChangesetCommitter;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandAuthorizer;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandContentHasher;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandHooks;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandTransaction;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeFieldValidation;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeHookOverruns;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeRevisionContents;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeStopwatch;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeWriteActions;
use Cbox\Cms\Core\Tests\Subscriptions\Fakes\FakePacing;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Idempotency\FakeIdempotencySession;
use Cbox\Cms\Testkit\Idempotency\FakeIdempotencyStore;
use Cbox\Cms\Testkit\Identity\FakeIdentity;
use Cbox\Cms\Testkit\ReceiptStore\FakeReceiptSession;
use Cbox\Cms\Testkit\ReceiptStore\FakeReceiptStore;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use Cbox\Cms\Testkit\Validation\FakeTypeValidators;
use LogicException;

/**
 * actor.register, actor.activate and actor.deactivate through the command pipeline with the fakes of the ports and
 * contracts they read (GUARDRAILS 9): the identity with an active staff member as the caller, the
 * authorizer and the committer a test chooses, and the fake idempotency and receipt stores. Each
 * run has an idempotency key of its own. Nothing touches a database.
 */
final class ActorCommandFakes
{
    public readonly FakeIdentity $identity;

    public readonly ActorId $admin;

    public FakeChangesetCommitter $committer;

    public CommandAuthorizer $authorizer;

    private int $calls = 0;

    public function __construct()
    {
        $this->identity = new FakeIdentity;
        $this->admin = $this->identity->addActor(ActorClass::Staff)->id;
        $this->committer = new FakeChangesetCommitter;
        $this->authorizer = new FakeCommandAuthorizer;
    }

    /**
     * Runs the command as the staff member $admin through an external envelope, or with the envelope
     * and access context a test gives.
     */
    public function run(RegisterActor|ActivateActor|DeactivateActor $command, bool $dryRun = false, ?Envelope $envelope = null, ?AccessContext $access = null): WriteResult
    {
        $clock = new FakeClock;
        $pipeline = $this->pipeline(new FakeIdempotencyStore($clock)->session(), new FakeReceiptStore($clock)->session());
        $envelope ??= Envelope::external(
            IssuingSurface::Cli,
            EnvelopeIssuer::Human,
            $this->admin,
            new IdempotencyKey('actor-command-'.++$this->calls),
            new CorrelationId('actor-command-correlation'),
            dryRun: $dryRun,
        );

        return $pipeline->run(new CommandCall($command, $envelope, $access ?? new AccessContext(
            new ActorPrincipal($this->admin, [], IssuerKind::Service, ClassificationAccess::Sensitive),
            [],
            ClassificationAccess::Personal,
        )));
    }

    /**
     * The command pipeline over these fakes with the idempotency and receipt stores given, so a test
     * that runs it more than once can keep them, and see a replay.
     */
    public function pipeline(FakeIdempotencySession $keys, FakeReceiptSession $receipts): CommandPipeline
    {
        $types = new FakeTypeCatalog;

        return new CommandPipeline(
            new FakeWriteActions([
                RegisterActor::class => $this->binding('actor.register', new RegisterActorAction($this->identity)),
                ActivateActor::class => $this->binding('actor.activate', new ActivateActorAction($this->identity)),
                DeactivateActor::class => $this->binding('actor.deactivate', new DeactivateActorAction($this->identity)),
            ]),
            $this->identity,
            $this->authorizer,
            $types,
            new FakeFieldValidation(new FakeTypeValidators),
            new FakeRevisionContents,
            $this->committer,
            $keys,
            $receipts,
            new FakeCommandContentHasher,
            new IdempotencySettings(WaitBudget::milliseconds(40)),
            new FakeCommandTransaction($keys, $receipts),
            new HookRunner(new FakeCommandHooks, new HookPlans($types), new FakeStopwatch, new FakeHookOverruns),
            new PipelineTelemetry(new FakeTelemetry, new FakeClock, new FakeStopwatch),
            new AwaitWaitLevel($receipts, new FakePacing, new WaitSettings(0)),
        );
    }

    /**
     * @return list<string> each error of the result as "<code> <path>"
     */
    public static function errors(WriteResult $result): array
    {
        return array_map(
            static fn (CatalogError $error): string => trim($error->code->value.' '.($error->path instanceof FieldPath ? $error->path->toString() : '')),
            $result->errors,
        );
    }

    /**
     * @return list<string> each read of the first changeset the committer got, as "<aggregate key> <version or ->"
     */
    public function reads(): array
    {
        return array_map(
            static fn (ReadVersion $read): string => $read->aggregate->aggregateKey().' '.($read->version->value ?? '-'),
            $this->committer->pending[0]->reads->reads ?? [],
        );
    }

    public function commitWith(CommitOutcome $outcome): void
    {
        $this->committer = new FakeChangesetCommitter($outcome);
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
