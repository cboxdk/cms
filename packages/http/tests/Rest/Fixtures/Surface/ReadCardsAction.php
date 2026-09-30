<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Tests\Rest\Fixtures\Surface;

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
 * The test-only query action of probe.cards, exposed on REST: it costs one per row and reads the
 * probe library as probe.read does.
 *
 * @implements QueryAction<ReadCards, Result>
 */
#[Action(handles: ReadCards::class, surfaces: [Surface::Rest])]
final readonly class ReadCardsAction implements QueryAction
{
    public function __construct(
        private ProbeLibrary $library = new ProbeLibrary,
    ) {}

    /**
     * @param  ReadCards  $query
     */
    #[Override]
    public function cost(Query $query): QueryCost
    {
        return new QueryCost($query->rows);
    }

    /**
     * @param  ReadCards  $query
     */
    #[Override]
    public function handle(Query $query): Result
    {
        return $this->library->read(new ReadProbe($query->rows));
    }
}
