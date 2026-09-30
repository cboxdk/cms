<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Tests\Fixtures\Surface;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Cbox\Cms\Contracts\Pipeline\Result;
use Cbox\Cms\Core\Tests\Reads\Probe\ProbeLibrary;
use Cbox\Cms\Core\Tests\Reads\Probe\ReadProbe;
use Override;

/**
 * The test-only query action of probe.agent_cards, exposed on MCP: it costs one per row asked for
 * and reads the cards of the library.
 *
 * @implements QueryAction<ReadAgentCards, Result>
 */
#[Action(handles: ReadAgentCards::class, surfaces: [Surface::Mcp])]
final readonly class ReadAgentCardsAction implements QueryAction
{
    public function __construct(
        private ProbeLibrary $library = new ProbeLibrary,
    ) {}

    /**
     * @param  ReadAgentCards  $query
     */
    #[Override]
    public function cost(Query $query): QueryCost
    {
        return new QueryCost($query->rows);
    }

    /**
     * @param  ReadAgentCards  $query
     */
    #[Override]
    public function handle(Query $query): Result
    {
        return $this->library->read(new ReadProbe($query->rows));
    }
}
