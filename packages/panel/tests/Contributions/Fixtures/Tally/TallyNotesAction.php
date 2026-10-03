<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Contributions\Fixtures\Tally;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use LogicException;

/**
 * The query action of tally.notes: the length of the note, with a summary and an owner. A note of
 * THROWS makes it throw, as a broken addon query would.
 *
 * @implements QueryAction<TallyNotes, TallyCount>
 */
#[Action(handles: TallyNotes::class)]
final readonly class TallyNotesAction implements QueryAction
{
    public const string THROWS = 'throws';

    public const string SUMMARY = 'Three open notes';

    public const string OWNER = 'Owner Olsen';

    public function cost(Query $query): QueryCost
    {
        return new QueryCost(1);
    }

    public function handle(Query $query): TallyCount
    {
        if ($query->note === self::THROWS) {
            throw new LogicException('The tally broke.');
        }

        return new TallyCount(strlen($query->note), self::SUMMARY, self::OWNER);
    }
}
