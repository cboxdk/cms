<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Reads\Probe;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Cbox\Cms\Contracts\Pipeline\Result;
use Override;

/**
 * The test-only query action of probe.read: it costs one per row asked for and reads the library.
 *
 * @implements QueryAction<ReadProbe, Result>
 */
#[Action(handles: ReadProbe::class)]
final readonly class ReadProbeAction implements QueryAction
{
    public function __construct(
        private ProbeLibrary $library,
    ) {}

    /**
     * @param  ReadProbe  $query
     */
    #[Override]
    public function cost(Query $query): QueryCost
    {
        return new QueryCost($query->rows);
    }

    /**
     * @param  ReadProbe  $query
     */
    #[Override]
    public function handle(Query $query): Result
    {
        return $this->library->read($query);
    }
}
