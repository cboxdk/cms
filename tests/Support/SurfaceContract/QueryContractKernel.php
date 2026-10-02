<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\SurfaceContract;

use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Cbox\Cms\Contracts\Pipeline\Result;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Reads\Domain\Dto\QuerySettings;
use Cbox\Cms\Core\Reads\Domain\ReadableFields;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeStopwatch;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryActions;
use Cbox\Cms\Core\Tests\Reads\Probe\ProbeQueryBinding;
use Cbox\Cms\Core\Tests\Reads\QueryWorld;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use LogicException;

/**
 * The kernel below the surfaces in a surface contract test of a query (GUARDRAILS 9): the
 * QueryWorld's fakes of the identity, the access resolver, the read audit and the read
 * transaction, with a ContractQueryAction bound to every query action of the registry and an
 * authorizer each scenario sets up; it binds its QueryPipeline in the container, which every
 * surface asks for its reads. A surface runs the real QueryPipeline over them: the
 * credential is verified, the query read by its generated codec, the principal authorized, the
 * query's cost checked and the result written by the result's codec. Nothing touches a database.
 */
final class QueryContractKernel
{
    /** Why the authorizer refuses the principal in the scenario that is unauthorized. */
    public const string REFUSAL = 'The surface contract test refuses this principal.';

    public readonly QueryWorld $world;

    /** @var list<Query> the queries the action handled in the current scenario */
    private array $handled = [];

    private ?Result $answer = null;

    private readonly ContractQueryAuthorizer $authorizer;

    public function __construct(CompiledRegistry $registry)
    {
        $this->authorizer = new ContractQueryAuthorizer;
        $this->world = new QueryWorld;
        $bindings = [];

        foreach ($registry->actions as $action) {
            if ($action->kind === ActionKind::Query) {
                $bindings[$this->queryClass($action->commandClass)] = ProbeQueryBinding::of(new ContractQueryAction($this), $action->command->value, $action->commandVersion);
            }
        }

        app()->instance(QueryPipeline::class, new QueryPipeline(
            new FakeQueryActions($bindings),
            $this->world->identity,
            $this->world->access,
            $this->authorizer,
            new QuerySettings(new QueryCost(QueryWorld::ANONYMOUS_BUDGET), new QueryCost(QueryWorld::ACTOR_BUDGET)),
            new ReadableFields(new FakeTypeCatalog),
            $this->world->audit,
            $this->world->transaction,
            new PipelineTelemetry($this->world->telemetry, $this->world->clock, new FakeStopwatch),
        ));
    }

    /**
     * Sets the kernel up for the scenario: whether the authorizer refuses the principal, and the
     * result an answered read gives.
     */
    public function script(QueryScenario $scenario, Result $answer): void
    {
        $this->handled = [];
        $this->answer = $answer;
        $this->authorizer->refusal = $scenario === QueryScenario::Unauthorized ? self::REFUSAL : null;
    }

    /**
     * What the ContractQueryAction answers with: the scenario's result.
     */
    public function handle(Query $query): Result
    {
        $this->handled[] = $query;

        return $this->answer ?? throw new LogicException('The query contract kernel has no scenario.');
    }

    /**
     * The queries handed to the action in the current scenario.
     *
     * @return list<Query>
     */
    public function handled(): array
    {
        return $this->handled;
    }

    /**
     * @return class-string<Query>
     */
    private function queryClass(string $class): string
    {
        return is_a($class, Query::class, true) ? $class : throw new LogicException(sprintf('The registry names %s as a query, and it is no Query.', $class));
    }
}
