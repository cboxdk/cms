<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Envelope\IssuerKind as EnvelopeIssuer;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Envelope\OnBehalfOf;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
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
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Pipeline\Actions\CommandPipeline;
use Cbox\Cms\Core\Pipeline\Domain\CommitOutcome;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCall;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeChangesetCommitter;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandAuthorizer;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeFieldValidation;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeWriteActions;
use Cbox\Cms\Core\Tests\Pipeline\Probe\ProbeBinding;
use Cbox\Cms\Core\Tests\Pipeline\Probe\ProbeCalls;
use Cbox\Cms\Core\Tests\Pipeline\Probe\ProbeShelf;
use Cbox\Cms\Core\Tests\Pipeline\Probe\ProbeType;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbe;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbeAction;
use Cbox\Cms\Testkit\Identity\FakeIdentity;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\Validation\FakeTypeValidators;

/**
 * The fakes a test of the command pipeline runs it with (GUARDRAILS 9): the identity with an
 * active editor, the probe type in the catalog and its validator, the probe action on its shelf,
 * and the authorizer and committer a test chooses. Nothing touches a database.
 */
final class PipelineWorld
{
    public const string ENTRY = '01936f5e-8a2b-7c3d-9e4f-0000000000e1';

    public const string HOME = '01936f5e-8a2b-7c3d-9e4f-0000000000a1';

    public readonly FakeIdentity $identity;

    public readonly ActorId $editor;

    public readonly ProbeShelf $shelf;

    public readonly ProbeCalls $calls;

    public readonly FakeFieldValidation $validation;

    public FakeCommandAuthorizer $authorizer;

    public FakeChangesetCommitter $committer;

    /** @var list<ReadVersion> */
    public array $extraReads = [];

    public ?Plan $unreadPlan = null;

    public function __construct()
    {
        $this->identity = new FakeIdentity;
        $this->editor = $this->identity->addActor(ActorClass::Staff)->id;
        $this->shelf = new ProbeShelf;
        $this->calls = new ProbeCalls;
        $this->validation = new FakeFieldValidation(new FakeTypeValidators(new ProbeType));
        $this->authorizer = new FakeCommandAuthorizer;
        $this->committer = new FakeChangesetCommitter;
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
        $envelope = Envelope::external(
            IssuingSurface::Rest,
            EnvelopeIssuer::Human,
            $this->editor,
            new IdempotencyKey('probe-rename-1'),
            new CorrelationId('probe-correlation'),
            new OnBehalfOf(...$onBehalfOf),
            dryRun: $dryRun,
        );

        return new CommandCall($command, $envelope, new AccessContext(
            new ActorPrincipal($this->editor, array_values($onBehalfOf), IssuerKind::Service, ClassificationAccess::Internal),
            [],
            ClassificationAccess::Internal,
        ));
    }

    public function run(RenameProbe $command, bool $dryRun = false, ActorId ...$onBehalfOf): WriteResult
    {
        return $this->pipeline()->run($this->call($command, $dryRun, ...$onBehalfOf));
    }
}
