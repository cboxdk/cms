<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Schema;

use Cbox\Cms\Contracts\Editors\TypeEditors;
use Cbox\Cms\Contracts\Identity\AccessRegion;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Cbox\Cms\Contracts\Results\QueryResult;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCall;
use Cbox\Cms\Core\Reads\Domain\Dto\QuerySettings;
use Cbox\Cms\Core\Reads\Domain\ReadableFields;
use Cbox\Cms\Core\Schema\Actions\DescribeTypeAction;
use Cbox\Cms\Core\Schema\Actions\ListTypesAction;
use Cbox\Cms\Core\Schema\Domain\Queries\DescribeType;
use Cbox\Cms\Core\Schema\Domain\Queries\ListTypes;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeAccessResolver;
use Cbox\Cms\Core\Tests\Access\Fakes\FakePermissions;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeStopwatch;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryActions;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryAuthorizer;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryTransaction;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeReadAudit;
use Cbox\Cms\Core\Tests\Reads\Probe\ProbeQueryBinding;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Editors\FakeTypeEditors;
use Cbox\Cms\Testkit\Editors\SampleEditors;
use Cbox\Cms\Testkit\Identity\FakeIdentity;
use Cbox\Cms\Testkit\Identity\ServiceCredentialSpec;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use DateInterval;

/**
 * type.list and type.describe through the real QueryPipeline with fakes for its ports (GUARDRAILS
 * 9): the identity, the access resolver, the read audit and the read transaction, the query
 * authorizer deciding from FakePermissions as the Postgres one does, and the two actions over a
 * FakeTypeEditors of $count sample types (SampleEditors), or over the TypeEditors a test gives. A reader is a service actor of the
 * identity whose regions reach the whole tree and whose classification access a test gives, with
 * a credential of the issuer kind a test gives, sent as a Bearer token; it holds no role, because
 * every actor may run the two queries (ActorQuery).
 */
final readonly class TypeEditorsWorld
{
    private const string ROOT = '0192a0c0-0000-7000-8000-000000000001';

    public FakeIdentity $identity;

    public FakeAccessResolver $access;

    public TypeEditors $editors;

    private FakeClock $clock;

    public function __construct(int $count = 3, ?TypeEditors $editors = null)
    {
        $this->clock = new FakeClock;
        $this->identity = new FakeIdentity($this->clock);
        $this->access = new FakeAccessResolver;
        $this->editors = $editors ?? new FakeTypeEditors(...SampleEditors::many($count));
    }

    /**
     * A new active service actor whose regions reach the whole tree with the classification access
     * given, and its credential, issued for a service or for an agent.
     */
    public function reader(ClassificationAccess $access, IssuerKind $issuer = IssuerKind::Service): TransportCredential
    {
        $actor = $this->identity->addActor(ActorClass::Service)->id;
        $this->access->grant($actor, [new AccessRegion(new NodePath(str_replace('-', '', self::ROOT)))], $access);

        return $this->identity->issue(new ServiceCredentialSpec($actor, $issuer, $issuer === IssuerKind::Agent ? $issuer->maximumCeiling() : ClassificationAccess::Sensitive, $this->clock->now()->add(new DateInterval('P1D'))));
    }

    /**
     * Runs the query through the pipeline, as the credential's actor or as the anonymous principal.
     */
    public function read(Query $query, ?TransportCredential $credential): QueryResult
    {
        return $this->pipeline()->run(new QueryCall($query, $credential));
    }

    /**
     * The query pipeline over the world's fakes, which a surface test binds in the container.
     */
    public function pipeline(): QueryPipeline
    {
        return new QueryPipeline(
            new FakeQueryActions([
                ListTypes::class => ProbeQueryBinding::of(new ListTypesAction($this->editors), 'type.list', 1),
                DescribeType::class => ProbeQueryBinding::of(new DescribeTypeAction($this->editors), 'type.describe', 1),
            ]),
            $this->identity,
            $this->access,
            FakeQueryAuthorizer::granting(new FakePermissions([self::ROOT => new NodePath(str_replace('-', '', self::ROOT))])),
            new QuerySettings(new QueryCost(200), new QueryCost(1000)),
            new ReadableFields(new FakeTypeCatalog),
            new FakeReadAudit,
            new FakeQueryTransaction(sessions: $this->access),
            new PipelineTelemetry(new FakeTelemetry, $this->clock, new FakeStopwatch),
        );
    }
}
