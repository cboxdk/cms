<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Envelope\IssuerKind as EnvelopeIssuer;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Envelope\OnBehalfOf;
use Cbox\Cms\Contracts\Envelope\UnitOfWork;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Idempotency\WaitBudget;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\IdempotencyStore\Domain\Dto\IdempotencySettings;
use Cbox\Cms\Core\Pipeline\Actions\CommandPipeline;
use Cbox\Cms\Core\Pipeline\Domain\CommitOutcome;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCall;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeChangesetCommitter;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandAuthorizer;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandContentHasher;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandTransaction;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeFieldValidation;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeWriteActions;
use Cbox\Cms\Core\Tests\Pipeline\Probe\ProbeBinding;
use Cbox\Cms\Core\Tests\Pipeline\Probe\ProbeCalls;
use Cbox\Cms\Core\Tests\Pipeline\Probe\ProbeShelf;
use Cbox\Cms\Core\Tests\Pipeline\Probe\ProbeType;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbe;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbeAction;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Idempotency\FakeIdempotencySession;
use Cbox\Cms\Testkit\Idempotency\FakeIdempotencyStore;
use Cbox\Cms\Testkit\Identity\FakeIdentity;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\ReceiptStore\FakeReceiptSession;
use Cbox\Cms\Testkit\ReceiptStore\FakeReceiptStore;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\Validation\FakeTypeValidators;

/**
 * The fakes a test of the command pipeline runs it with (GUARDRAILS 9): the identity with an
 * active editor, the probe type in the catalog and its validator, the probe action on its shelf,
 * the authorizer and committer a test chooses, and the fake idempotency and receipt stores, whose
 * sessions the fake command transaction begins and ends around each call, with the wait budget
 * BUDGET_MILLISECONDS. The committer stores each changeset's receipt in the receipt session: by
 * default as the changeset FakeChangesetCommitter::CHANGESET, and after committing() with a new id
 * from a FakeIdGenerator on the world's clock for every commit. Nothing touches a database.
 */
final class PipelineWorld
{
    public const string ENTRY = '01936f5e-8a2b-7c3d-9e4f-0000000000e1';

    public const string HOME = '01936f5e-8a2b-7c3d-9e4f-0000000000a1';

    /** The kernel's wait budget in these tests, short so an in-flight claim returns quickly. */
    public const int BUDGET_MILLISECONDS = 40;

    public const string KEY = 'probe-rename-1';

    public readonly FakeIdentity $identity;

    public readonly ActorId $editor;

    public readonly ProbeShelf $shelf;

    public readonly ProbeCalls $calls;

    public readonly FakeFieldValidation $validation;

    public FakeCommandAuthorizer $authorizer;

    public FakeChangesetCommitter $committer;

    public readonly FakeClock $clock;

    public readonly FakeIdempotencyStore $keys;

    public readonly FakeReceiptStore $receipts;

    public readonly FakeIdempotencySession $keySession;

    public readonly FakeReceiptSession $receiptSession;

    public readonly FakeCommandTransaction $transaction;

    public readonly FakeCommandContentHasher $hasher;

    /** @var list<ReadVersion> */
    public array $extraReads = [];

    /** How many calls call() has built, each with a key of its own. */
    private int $keyCount = 0;

    public ?Plan $unreadPlan = null;

    public function __construct()
    {
        $this->identity = new FakeIdentity;
        $this->editor = $this->identity->addActor(ActorClass::Staff)->id;
        $this->shelf = new ProbeShelf;
        $this->calls = new ProbeCalls;
        $this->validation = new FakeFieldValidation(new FakeTypeValidators(new ProbeType));
        $this->authorizer = new FakeCommandAuthorizer;
        $this->clock = new FakeClock;
        $this->keys = new FakeIdempotencyStore($this->clock);
        $this->receipts = new FakeReceiptStore($this->clock);
        $this->keySession = $this->keys->session();
        $this->receiptSession = $this->receipts->session();
        $this->transaction = new FakeCommandTransaction($this->keySession, $this->receiptSession);
        $this->hasher = new FakeCommandContentHasher;
        $this->committer = new FakeChangesetCommitter(receipts: $this->receiptSession);
    }

    /**
     * Commits every call as a new changeset at the world's clock and stores its receipt with
     * these projections.
     */
    public function committing(ProjectionStatus ...$projections): FakeChangesetCommitter
    {
        return $this->committer = new FakeChangesetCommitter(
            receipts: $this->receiptSession,
            ids: new FakeIdGenerator(clock: $this->clock),
            projections: array_values($projections),
        );
    }

    public function refuse(string $reason): self
    {
        $this->authorizer = new FakeCommandAuthorizer($reason);

        return $this;
    }

    public function commitWith(CommitOutcome $outcome): self
    {
        $this->committer = new FakeChangesetCommitter($outcome);

        return $this;
    }

    public function pipeline(): CommandPipeline
    {
        $action = new RenameProbeAction($this->shelf, $this->calls, $this->extraReads, $this->unreadPlan);

        return new CommandPipeline(
            new FakeWriteActions([RenameProbe::class => ProbeBinding::of($action)]),
            $this->identity,
            $this->authorizer,
            new FakeTypeCatalog(ProbeType::definition()),
            $this->validation,
            $this->committer,
            $this->keySession,
            $this->receiptSession,
            $this->hasher,
            new IdempotencySettings(WaitBudget::milliseconds(self::BUDGET_MILLISECONDS)),
            $this->transaction,
        );
    }

    public function entry(): EntryId
    {
        return EntryId::fromString(self::ENTRY);
    }

    public static function fields(string $label): FieldValues
    {
        return new FieldValues(new FieldMap(new NamedValue(new FieldHandle('label'), new TextValue($label))));
    }

    public function command(?FieldValues $fields = null, ?TypeId $type = null, ReadVersions $expected = new ReadVersions): RenameProbe
    {
        return new RenameProbe(
            $this->entry(),
            $type ?? TypeId::fromString(ProbeType::ID),
            NodeId::fromString(self::HOME),
            $fields ?? self::fields('Before'),
            $expected,
        );
    }

    public function call(RenameProbe $command, bool $dryRun = false, ActorId ...$onBehalfOf): CommandCall
    {
        return $this->withEnvelope($command, Envelope::external(
            IssuingSurface::Rest,
            EnvelopeIssuer::Human,
            $this->editor,
            new IdempotencyKey(self::KEY.'-call-'.++$this->keyCount),
            new CorrelationId('probe-correlation'),
            new OnBehalfOf(...$onBehalfOf),
            dryRun: $dryRun,
        ));
    }

    /**
     * A call through REST with the idempotency key and wait level given, by the actor given or the
     * editor.
     */
    public function keyed(RenameProbe $command, string $key, WaitLevel $waitLevel = WaitLevel::Commit, ?ActorId $actor = null, bool $dryRun = false): CommandCall
    {
        return $this->withEnvelope($command, Envelope::external(
            IssuingSurface::Rest,
            EnvelopeIssuer::Human,
            $actor ?? $this->editor,
            new IdempotencyKey($key),
            new CorrelationId('probe-correlation'),
            dryRun: $dryRun,
            waitLevel: $waitLevel,
        ));
    }

    /**
     * A call from a subscriber, an internal issuer, whose key the envelope derives from the unit.
     */
    public function internal(RenameProbe $command, string $unit): CommandCall
    {
        return $this->withEnvelope($command, Envelope::internal(
            IssuingSurface::Subscriber,
            EnvelopeIssuer::System,
            $this->editor,
            new UnitOfWork($unit),
            new CorrelationId('probe-correlation'),
        ));
    }

    private function withEnvelope(RenameProbe $command, Envelope $envelope): CommandCall
    {
        return new CommandCall($command, $envelope, new AccessContext(
            new ActorPrincipal($envelope->actor, $envelope->onBehalfOf->chain, IssuerKind::Service, ClassificationAccess::Internal),
            [],
            ClassificationAccess::Internal,
        ));
    }

    public function run(RenameProbe $command, bool $dryRun = false, ActorId ...$onBehalfOf): WriteResult
    {
        return $this->pipeline()->run($this->call($command, $dryRun, ...$onBehalfOf));
    }
}
