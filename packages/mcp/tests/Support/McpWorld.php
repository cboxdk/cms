<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Tests\Support;

use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Contracts\Codecs\JsonSchema;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Cbox\Cms\Core\Pipeline\Actions\RunExposedCommand;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCodec;
use Cbox\Cms\Core\Reads\Domain\Dto\QuerySettings;
use Cbox\Cms\Core\Reads\Domain\QueryCodecs;
use Cbox\Cms\Core\Reads\Domain\ReadableFields;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\ScanRoots;
use Cbox\Cms\Core\Registry\Domain\RegistryCompiler;
use Cbox\Cms\Core\Registry\Infrastructure\AttributeScanner;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;
use Cbox\Cms\Core\Tests\Pipeline\ExposedWorld;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeStopwatch;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryActions;
use Cbox\Cms\Core\Tests\Reads\Probe\AgentCardType;
use Cbox\Cms\Core\Tests\Reads\Probe\ProbeQueryBinding;
use Cbox\Cms\Core\Tests\Reads\QueryWorld;
use Cbox\Cms\Mcp\Boundary\KernelSchemas;
use Cbox\Cms\Mcp\Boundary\ToolCompiler;
use Cbox\Cms\Mcp\Domain\McpTools;
use Cbox\Cms\Mcp\Tests\Fixtures\Surface\ReadAgentCards;
use Cbox\Cms\Mcp\Tests\Fixtures\Surface\ReadAgentCardsAction;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use Illuminate\Contracts\Container\Container;

/**
 * What a test of the MCP surface runs with (GUARDRAILS 9): the registry cms:build compiles from two
 * scan roots, the MCP fixtures (the test-only write action of probe.rename and the query
 * probe.agent_cards with its action, both exposed on MCP) and the core's probe, which declares the
 * command probe.rename; the codecs of both; the ExposedWorld, whose command pipeline runs every
 * write with fakes; and a query pipeline over the QueryWorld's fakes, whose library holds two cards
 * of test:agent_card, AgentCardType, with a field for each way an agent sees a field or not.
 */
final readonly class McpWorld
{
    public const string PACKAGE = 'acme/mcp-probe';

    public const string QUERY = 'probe.agent_cards';

    public const string WRITE_TOOL = 'probe-rename-v1';

    public const string READ_TOOL = 'probe-agent_cards-v1';

    public ExposedWorld $exposed;

    public QueryWorld $reads;

    public function __construct()
    {
        $this->exposed = new ExposedWorld;
        $this->reads = new QueryWorld(AgentCardType::definition(), [
            AgentCardType::card(QueryWorld::ENTRIES[0]),
            AgentCardType::card(QueryWorld::ENTRIES[1]),
        ]);
    }

    public static function fixtures(): ScanRoot
    {
        return new ScanRoot(self::PACKAGE, __DIR__.'/../Fixtures/Surface');
    }

    /**
     * The scan root of the core's probe, which declares the command probe.rename.
     */
    public static function probe(): ScanRoot
    {
        return new ScanRoot('acme/probe', dirname(__DIR__, 3).'/core/tests/Pipeline/Probe');
    }

    /**
     * The registry cms:build compiles from the scan roots, the fixtures' and the probe's unless
     * others are given.
     */
    public static function registry(?ScanRoots $roots = null): CompiledRegistry
    {
        return new RegistryCompiler()->compile(new AttributeScanner()->scan($roots ?? new ScanRoots(self::fixtures(), self::probe())));
    }

    public static function queryCodec(): QueryCodec
    {
        return new QueryCodec(
            new CommandName(self::QUERY),
            1,
            new ReadAgentCardsCodec,
            new JsonSchema(ReadAgentCardsCodec::SCHEMA),
            new AgentCardsCodec,
            new JsonSchema(AgentCardsCodec::SCHEMA),
        );
    }

    public static function commandCodecs(): CommandCodecs
    {
        return ExposedWorld::codecs();
    }

    public static function queryCodecs(): QueryCodecs
    {
        return new QueryCodecs(self::queryCodec());
    }

    /**
     * The MCP tools of the registry, with the codecs of probe.rename and probe.agent_cards.
     */
    public static function tools(?CompiledRegistry $registry = null): McpTools
    {
        return new ToolCompiler()->compile($registry ?? self::registry(), self::commandCodecs(), self::queryCodecs(), KernelSchemas::envelope());
    }

    /**
     * The query pipeline over the QueryWorld's fakes, with the action of probe.agent_cards.
     */
    public function queries(): QueryPipeline
    {
        return new QueryPipeline(
            new FakeQueryActions([ReadAgentCards::class => ProbeQueryBinding::of(new ReadAgentCardsAction($this->reads->library), self::QUERY, 1)]),
            $this->reads->identity,
            $this->reads->access,
            $this->reads->authorizer,
            new QuerySettings(new QueryCost(QueryWorld::ANONYMOUS_BUDGET), new QueryCost(QueryWorld::ACTOR_BUDGET)),
            new ReadableFields(new FakeTypeCatalog($this->reads->type)),
            $this->reads->audit,
            $this->reads->transaction,
            new PipelineTelemetry(new FakeTelemetry, new FakeClock, new FakeStopwatch),
        );
    }

    /**
     * Binds the world into the container: the tools, the action that runs a write and the query
     * pipeline. A test that changes how the world commits binds it again.
     */
    public function bind(Container $container, ?McpTools $tools = null): self
    {
        $container->instance(McpTools::class, $tools ?? self::tools());
        $container->instance(RunExposedCommand::class, $this->exposed->action());
        $container->instance(QueryPipeline::class, $this->queries());

        return $this;
    }
}
