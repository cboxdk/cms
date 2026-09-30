<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Tests\Rest\Support;

use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Contracts\Codecs\JsonSchema;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCodec;
use Cbox\Cms\Core\Reads\Domain\Dto\QuerySettings;
use Cbox\Cms\Core\Reads\Domain\QueryCodecs;
use Cbox\Cms\Core\Reads\Domain\ReadableFields;
use Cbox\Cms\Core\Registry\Actions\BuildRegistry;
use Cbox\Cms\Core\Registry\Adapter\FileOpenApiDocuments;
use Cbox\Cms\Core\Registry\Adapter\FileRegistryCache;
use Cbox\Cms\Core\Registry\Boundary\RegistryCacheCodec;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\ScanRoots;
use Cbox\Cms\Core\Registry\Domain\RegistryCompiler;
use Cbox\Cms\Core\Registry\Infrastructure\AttributeScanner;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;
use Cbox\Cms\Core\Tests\Pipeline\ExposedWorld;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeStopwatch;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryActions;
use Cbox\Cms\Core\Tests\Reads\Probe\ProbeCardType;
use Cbox\Cms\Core\Tests\Reads\Probe\ProbeQueryBinding;
use Cbox\Cms\Core\Tests\Reads\QueryWorld;
use Cbox\Cms\Http\Tests\Rest\Fixtures\Surface\ReadCards;
use Cbox\Cms\Http\Tests\Rest\Fixtures\Surface\ReadCardsAction;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;

/**
 * What a test of the REST surface runs with (GUARDRAILS 9): the registry cms:build compiles from
 * two scan roots, the REST fixtures (the test-only write action of probe.rename and the query
 * probe.cards with its action, both exposed on REST) and the core's probe, which declares the
 * command probe.rename; the codecs of both; the ExposedWorld, whose command pipeline runs every
 * write with fakes; and a query pipeline over the QueryWorld's fakes, whose library answers
 * probe.cards.
 */
final readonly class RestWorld
{
    public const string PACKAGE = 'acme/rest-probe';

    public const string QUERY = 'probe.cards';

    public ExposedWorld $exposed;

    public QueryWorld $reads;

    public function __construct()
    {
        $this->exposed = new ExposedWorld;
        $this->reads = new QueryWorld;
    }

    public static function roots(): ScanRoots
    {
        return new ScanRoots(
            new ScanRoot(self::PACKAGE, __DIR__.'/../Fixtures/Surface'),
            new ScanRoot('acme/probe', dirname(__DIR__, 4).'/core/tests/Pipeline/Probe'),
        );
    }

    public static function queryCodec(): QueryCodec
    {
        return new QueryCodec(
            new CommandName(self::QUERY),
            1,
            new ReadCardsCodec,
            new JsonSchema(ReadCardsCodec::SCHEMA),
            new ProbeCardsCodec,
            new JsonSchema(ProbeCardsCodec::SCHEMA),
        );
    }

    public static function queryCodecs(): QueryCodecs
    {
        return new QueryCodecs(self::queryCodec());
    }

    /**
     * Runs cms:build's action over the scan roots into the directory: the registry files and the
     * OpenAPI document.
     */
    public static function build(string $directory): CompiledRegistry
    {
        return new BuildRegistry(
            new AttributeScanner,
            new RegistryCompiler,
            new FileRegistryCache($directory, new RegistryCacheCodec),
            new FileOpenApiDocuments($directory, ExposedWorld::codecs(), self::queryCodecs()),
        )->build(self::roots());
    }

    /**
     * The query pipeline over the QueryWorld's fakes, with the action of probe.cards.
     */
    public function queries(): QueryPipeline
    {
        return new QueryPipeline(
            new FakeQueryActions([ReadCards::class => ProbeQueryBinding::of(new ReadCardsAction($this->reads->library), self::QUERY, 1)]),
            $this->reads->identity,
            $this->reads->access,
            $this->reads->authorizer,
            new QuerySettings(new QueryCost(QueryWorld::ANONYMOUS_BUDGET), new QueryCost(QueryWorld::ACTOR_BUDGET)),
            new ReadableFields(new FakeTypeCatalog(ProbeCardType::definition())),
            $this->reads->audit,
            $this->reads->transaction,
            new PipelineTelemetry(new FakeTelemetry, new FakeClock, new FakeStopwatch),
        );
    }
}
