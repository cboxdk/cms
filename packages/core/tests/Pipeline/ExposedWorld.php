<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Envelope\RequestEnvelope;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Pipeline\Actions\RunExposedCommand;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCodec;
use Cbox\Cms\Core\Pipeline\Domain\Dto\ExposedCall;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeAccessContexts;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbeCodec;
use Cbox\Cms\Testkit\Identity\ServiceCredentialSpec;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use DateInterval;

/**
 * The fakes a test of an exposed surface runs with (GUARDRAILS 9): the PipelineWorld, committing
 * every call as a new changeset, with an active service actor, SERVICE, whose credentials a test
 * issues, the fake AccessContexts, which gives it internal access, the codec of probe.rename
 * version 1, and an IdGenerator for correlation ids, seeded CORRELATION_SEED.
 */
final readonly class ExposedWorld
{
    public const string COMMAND = 'probe.rename';

    public const int CORRELATION_SEED = 11;

    public PipelineWorld $world;

    public FakeAccessContexts $contexts;

    public FakeIdGenerator $ids;

    public ActorId $service;

    public function __construct()
    {
        $this->world = new PipelineWorld;
        $this->world->committing();
        $this->contexts = new FakeAccessContexts;
        $this->ids = new FakeIdGenerator(self::CORRELATION_SEED);
        $this->service = $this->world->identity->addActor(ActorClass::Service)->id;
        $this->contexts->grant($this->service, ClassificationAccess::Internal);
    }

    /**
     * A credential of the service actor, of the issuer kind given, for the chain given, valid for a
     * day from the identity's clock.
     */
    public function credential(IssuerKind $kind = IssuerKind::Service, ActorId ...$onBehalfOf): TransportCredential
    {
        return $this->world->identity->issue(new ServiceCredentialSpec(
            $this->service,
            $kind,
            $kind->maximumCeiling(),
            $this->world->clock->now()->add(new DateInterval('P1D')),
            array_values($onBehalfOf),
        ));
    }

    public static function codec(): CommandCodec
    {
        return new CommandCodec(new CommandName(self::COMMAND), 1, new RenameProbeCodec);
    }

    public static function codecs(): CommandCodecs
    {
        return new CommandCodecs(self::codec());
    }

    /**
     * The JSON document of a probe.rename of the world's entry with the fields given, or a label.
     */
    public function document(?FieldValues $fields = null): string
    {
        return new RenameProbeCodec()->encode($this->world->command($fields), ClassificationAccess::Sensitive);
    }

    public function action(): RunExposedCommand
    {
        return new RunExposedCommand($this->world->identity, $this->contexts, $this->ids, $this->world->pipeline());
    }

    public function call(?TransportCredential $credential, RequestEnvelope $envelope, ?string $document = null, Surface $surface = Surface::Rest): ExposedCall
    {
        return new ExposedCall($surface, $credential, $envelope, self::codec(), $document ?? $this->document());
    }
}
