<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Delivery;

use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Cbox\Cms\Core\Delivery\Actions\DeliverPath;
use Cbox\Cms\Core\Delivery\Domain\DeliveryAuthorizer;
use Cbox\Cms\Core\Delivery\Domain\Dto\Delivery;
use Cbox\Cms\Core\Delivery\Domain\Dto\DeliveryRequest;
use Cbox\Cms\Core\Delivery\Domain\Dto\DeliverySettings;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Reads\Domain\Dto\QuerySettings;
use Cbox\Cms\Core\Reads\Domain\ReadableFields;
use Cbox\Cms\Core\Routing\Actions\ResolvePathAction;
use Cbox\Cms\Core\Routing\Domain\Queries\ResolvePath;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeAccessResolver;
use Cbox\Cms\Core\Tests\Delivery\Fakes\FakeDeliveryDocuments;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeStopwatch;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryActions;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryTransaction;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeReadAudit;
use Cbox\Cms\Core\Tests\Reads\Probe\ProbeQueryBinding;
use Cbox\Cms\Core\Tests\Routing\ResolveWorld;
use Cbox\Cms\Testkit\Cache\FakeFragmentStore;
use Cbox\Cms\Testkit\Codecs\FakeRecordCodecs;
use Cbox\Cms\Testkit\Identity\FakeIdentity;
use Cbox\Cms\Testkit\Identity\ServiceCredentialSpec;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use DateInterval;

/**
 * The delivery API's resolve with fakes (GUARDRAILS 9): the structure and placements of a
 * ResolveWorld, path.resolve through the query pipeline with the DeliveryAuthorizer, a
 * FakeFragmentStore, FakeRecordCodecs over the world's catalog, FakeDeliveryDocuments, and the
 * identity, access and read transaction of the pipeline, all on the ResolveWorld's clock. The
 * pipeline's spans say how often it ran. staff() is the credential of an actor whose access is
 * internal, member() of one whose access is public.
 */
final class DeliveryWorld
{
    public const string POSITION = '9100';

    public readonly ResolveWorld $resolve;

    public readonly FakeFragmentStore $fragments;

    public readonly FakeDeliveryDocuments $documents;

    public readonly FakeTelemetry $telemetry;

    public readonly FakeIdentity $identity;

    public readonly FakeAccessResolver $access;

    public DeliverySettings $settings;

    public function __construct()
    {
        $this->resolve = new ResolveWorld;
        $this->fragments = new FakeFragmentStore($this->resolve->clock);
        $this->documents = new FakeDeliveryDocuments;
        $this->telemetry = new FakeTelemetry;
        $this->identity = new FakeIdentity($this->resolve->clock);
        $this->access = new FakeAccessResolver;
        $this->settings = new DeliverySettings(maxAge: 600, staleWhileRevalidate: 30, staleIfError: 3600);
    }

    public function deliver(?string $site, ?string $locale, ?string $path, ?string $debug = null, ?TransportCredential $credential = null): Delivery
    {
        return $this->action()->deliver(new DeliveryRequest($site, $locale, $path, $debug, $credential));
    }

    public function action(): DeliverPath
    {
        return new DeliverPath(
            $this->pipeline(),
            $this->fragments,
            $this->documents,
            new FakeRecordCodecs($this->resolve->catalog()),
            $this->resolve->catalog(),
            $this->resolve->sites(),
            $this->settings,
            $this->resolve->clock,
        );
    }

    /**
     * How many times the query pipeline ran path.resolve.
     */
    public function resolutions(): int
    {
        return count($this->telemetry->spansNamed('path.resolve'));
    }

    public function staff(): TransportCredential
    {
        return $this->credential(ClassificationAccess::Internal);
    }

    public function member(): TransportCredential
    {
        return $this->credential(ClassificationAccess::Public);
    }

    private function credential(ClassificationAccess $access): TransportCredential
    {
        $actor = $this->identity->addActor(ActorClass::Service)->id;
        $this->access->grant($actor, [], $access);

        return $this->identity->issue(new ServiceCredentialSpec($actor, IssuerKind::Service, ClassificationAccess::Sensitive, $this->resolve->clock->now()->add(new DateInterval('P1D'))));
    }

    private function pipeline(): QueryPipeline
    {
        return new QueryPipeline(
            new FakeQueryActions([ResolvePath::class => ProbeQueryBinding::of($this->resolve->action(), 'path.resolve', 1)]),
            $this->identity,
            $this->access,
            new DeliveryAuthorizer,
            new QuerySettings(new QueryCost(ResolvePathAction::COST), new QueryCost(ResolvePathAction::COST)),
            new ReadableFields($this->resolve->catalog()),
            new FakeReadAudit,
            new FakeQueryTransaction(new CommitPosition(self::POSITION), $this->access),
            new PipelineTelemetry($this->telemetry, $this->resolve->clock, new FakeStopwatch),
        );
    }
}
