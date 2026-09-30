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
use Cbox\Cms\Contracts\Identity\DeactivationSource;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\IdempotencyStore\Domain\Dto\IdempotencySettings;
use Cbox\Cms\Core\Identity\Actions\DeactivateActorAction;
use Cbox\Cms\Core\Identity\Domain\Commands\DeactivateActor;
use Cbox\Cms\Core\Pipeline\Actions\CommandPipeline;
use Cbox\Cms\Core\Pipeline\Actions\HookRunner;
use Cbox\Cms\Core\Pipeline\Domain\CommitOutcome;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCall;
use Cbox\Cms\Core\Pipeline\Domain\HookPlans;
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
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Idempotency\FakeIdempotencyStore;
use Cbox\Cms\Testkit\Identity\FakeIdentity;
use Cbox\Cms\Testkit\ReceiptStore\FakeReceiptStore;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\Validation\FakeTypeValidators;

/**
 * actor.deactivate through the command pipeline with the fakes of the ports and contracts it reads
 * (GUARDRAILS 9): the identity with an active staff member as the caller, the authorizer and the
 * committer a test chooses, and the fake idempotency and receipt stores. Each run has an
 * idempotency key of its own. Nothing touches a database.
 */
final class DeactivationFakes
{
    public readonly FakeIdentity $identity;

    public readonly ActorId $admin;

    public FakeChangesetCommitter $committer;

    public FakeCommandAuthorizer $authorizer;

    private int $calls = 0;

    public function __construct()
    {
        $this->identity = new FakeIdentity;
        $this->admin = $this->identity->addActor(ActorClass::Staff)->id;
        $this->committer = new FakeChangesetCommitter;
        $this->authorizer = new FakeCommandAuthorizer;
    }

    public function run(ActorId $target, DeactivationSource $source = DeactivationSource::Local, bool $dryRun = false): WriteResult
    {
        $clock = new FakeClock;
        $keys = new FakeIdempotencyStore($clock)->session();
        $receipts = new FakeReceiptStore($clock)->session();
        $types = new FakeTypeCatalog;
        $pipeline = new CommandPipeline(
            new FakeWriteActions([DeactivateActor::class => DeactivationWorld::binding(new DeactivateActorAction($this->identity))]),
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
        );
        $envelope = Envelope::external(
            IssuingSurface::Rest,
            EnvelopeIssuer::Human,
            $this->admin,
            new IdempotencyKey('deactivate-'.++$this->calls),
            new CorrelationId('deactivate-correlation'),
            dryRun: $dryRun,
        );

        return $pipeline->run(new CommandCall(new DeactivateActor($target, $source), $envelope, new AccessContext(
            new ActorPrincipal($this->admin, [], IssuerKind::Service, ClassificationAccess::Sensitive),
            [],
            ClassificationAccess::Internal,
        )));
    }

    public function commitWith(CommitOutcome $outcome): void
    {
        $this->committer = new FakeChangesetCommitter($outcome);
    }
}
