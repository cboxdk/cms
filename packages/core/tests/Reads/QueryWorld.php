<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Reads;

use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Fields\ExtensionFields;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Cbox\Cms\Contracts\Results\QueryResult;
use Cbox\Cms\Contracts\Results\ReadContent;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCall;
use Cbox\Cms\Core\Reads\Domain\Dto\QuerySettings;
use Cbox\Cms\Core\Reads\Domain\ReadableFields;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeAccessResolver;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeStopwatch;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryActions;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryAuthorizer;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryTransaction;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeReadAudit;
use Cbox\Cms\Core\Tests\Reads\Probe\ProbeCardType;
use Cbox\Cms\Core\Tests\Reads\Probe\ProbeLibrary;
use Cbox\Cms\Core\Tests\Reads\Probe\ProbeQueryBinding;
use Cbox\Cms\Core\Tests\Reads\Probe\ReadProbe;
use Cbox\Cms\Core\Tests\Reads\Probe\ReadProbeAction;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Identity\FakeIdentity;
use Cbox\Cms\Testkit\Identity\ServiceCredentialSpec;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use DateInterval;

/**
 * The query pipeline with the test-only query probe.read and the fakes of its ports and of the
 * contracts it reads (GUARDRAILS 9): the identity, the type catalog, the access resolver, the read
 * audit and the read transaction. The library holds three cards of test:card, each with every
 * field of the type and an undeclared one, or the type and cards a test gives; the reader is a
 * service actor whose credential's ceiling is sensitive and whose grants allow confidential, and
 * agentCredential() gives it a credential issued for an agent.
 */
final class QueryWorld
{
    public const string POSITION = '4827';

    public const string NODE = '01936f5e-8a2b-7c3d-9e4f-0000000000a1';

    /** @var list<string> the entries of the library's cards, in order */
    public const array ENTRIES = [
        '01936f5e-8a2b-7c3d-9e4f-0000000000e1',
        '01936f5e-8a2b-7c3d-9e4f-0000000000e2',
        '01936f5e-8a2b-7c3d-9e4f-0000000000e3',
    ];

    public const int ANONYMOUS_BUDGET = 2;

    public const int ACTOR_BUDGET = 3;

    public readonly FakeClock $clock;

    /** Where the pipeline exports each call's span and metrics. */
    public readonly FakeTelemetry $telemetry;

    public readonly FakeIdentity $identity;

    public readonly ActorId $reader;

    public readonly TransportCredential $credential;

    public readonly ProbeLibrary $library;

    public readonly FakeAccessResolver $access;

    public readonly FakeReadAudit $audit;

    public readonly FakeQueryTransaction $transaction;

    public FakeQueryAuthorizer $authorizer;

    public readonly TypeDefinition $type;

    /**
     * @param  list<ReadContent>|null  $cards  the library's cards, or a card of test:card per ENTRIES
     */
    public function __construct(?TypeDefinition $type = null, ?array $cards = null)
    {
        $this->type = $type ?? ProbeCardType::definition();
        $this->clock = new FakeClock;
        $this->telemetry = new FakeTelemetry;
        $this->identity = new FakeIdentity($this->clock);
        $this->reader = $this->identity->addActor(ActorClass::Service)->id;
        $this->credential = $this->identity->issue(new ServiceCredentialSpec($this->reader, IssuerKind::Service, ClassificationAccess::Sensitive, $this->clock->now()->add(new DateInterval('P1D'))));
        $this->library = new ProbeLibrary($cards ?? array_map(self::card(...), self::ENTRIES));
        $this->access = new FakeAccessResolver()->grant($this->reader, [], ClassificationAccess::Confidential);
        $this->audit = new FakeReadAudit;
        $this->transaction = new FakeQueryTransaction(new CommitPosition(self::POSITION), $this->access, $this->audit);
        $this->authorizer = new FakeQueryAuthorizer;
    }

    /**
     * A card of test:card with every field of the type, `ext.probe.tag` and `ext.probe.code`
     * included, and the undeclared field `stray` and extender `other`.
     */
    public static function card(string $entry): ReadContent
    {
        return new ReadContent(
            EntryId::fromString($entry),
            NodeId::fromString(self::NODE),
            TypeId::fromString(ProbeCardType::ID),
            new FieldValues(
                new FieldMap(...array_map(
                    static fn (string $handle): NamedValue => new NamedValue(new FieldHandle($handle), new TextValue($handle.' of '.$entry)),
                    ['label', 'note', 'memo', 'diagnosis', 'stray'],
                )),
                new ExtensionFields(new FieldNamespace(ProbeCardType::EXTENDER), new FieldMap(
                    new NamedValue(new FieldHandle('tag'), new TextValue('tag')),
                    new NamedValue(new FieldHandle('code'), new TextValue('code')),
                )),
                new ExtensionFields(new FieldNamespace('other'), new FieldMap(new NamedValue(new FieldHandle('tag'), new TextValue('other')))),
            ),
        );
    }

    public function refuse(string $reason): self
    {
        $this->authorizer = new FakeQueryAuthorizer($reason);

        return $this;
    }

    public function pipeline(): QueryPipeline
    {
        return new QueryPipeline(
            new FakeQueryActions([ReadProbe::class => ProbeQueryBinding::of(new ReadProbeAction($this->library))]),
            $this->identity,
            $this->access,
            $this->authorizer,
            new QuerySettings(new QueryCost(self::ANONYMOUS_BUDGET), new QueryCost(self::ACTOR_BUDGET)),
            new ReadableFields(new FakeTypeCatalog($this->type)),
            $this->audit,
            $this->transaction,
            new PipelineTelemetry($this->telemetry, $this->clock, new FakeStopwatch),
        );
    }

    public function read(int $rows = 1, bool $anonymous = false): QueryResult
    {
        return $this->pipeline()->run(new QueryCall(new ReadProbe($rows), $anonymous ? null : $this->credential));
    }

    /**
     * A credential of the reader issued for an agent, whose ceiling is the most an agent's may be,
     * confidential (PRD 2.31).
     */
    public function agentCredential(): TransportCredential
    {
        return $this->identity->issue(new ServiceCredentialSpec($this->reader, IssuerKind::Agent, IssuerKind::Agent->maximumCeiling(), $this->clock->now()->add(new DateInterval('P1D'))));
    }
}
