<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Contributions\Fixtures\Tally;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Cbox\Cms\Contracts\Pipeline\QueryCost;

/**
 * The query action of tally.board, exposed on REST and Inertia so the same data the addon's page
 * shows can be read over REST: a fixed count with its summary and owner.
 *
 * @implements QueryAction<TallyBoard, TallyCount>
 */
#[Action(handles: TallyBoard::class, surfaces: [Surface::Rest, Surface::Inertia])]
final readonly class TallyBoardAction implements QueryAction
{
    public const int COUNT = 7;

    public const string SUMMARY = 'Seven on the board';

    public const string OWNER = 'Board Bendtsen';

    public function cost(Query $query): QueryCost
    {
        return new QueryCost(1);
    }

    public function handle(Query $query): TallyCount
    {
        return new TallyCount(self::COUNT, self::SUMMARY, self::OWNER);
    }
}
