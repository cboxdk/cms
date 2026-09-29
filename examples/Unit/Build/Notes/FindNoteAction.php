<?php

declare(strict_types=1);

namespace Examples\Unit\Build\Notes;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Override;

/**
 * The query action of note.find, exposed on REST and the command line. cms:build registers it in
 * actions.php under the name and version FindNote declares.
 *
 * @implements QueryAction<FindNote, FoundNote>
 */
#[Action(handles: FindNote::class, surfaces: [Surface::Cli, Surface::Rest])]
final readonly class FindNoteAction implements QueryAction
{
    /**
     * @param  list<string>  $titles  the titles of the notes there are
     */
    public function __construct(
        private array $titles,
    ) {}

    /**
     * One lookup by title.
     */
    #[Override]
    public function cost(Query $query): QueryCost
    {
        return new QueryCost(1);
    }

    /**
     * @param  FindNote  $query
     */
    #[Override]
    public function handle(Query $query): FoundNote
    {
        return new FoundNote(in_array($query->title, $this->titles, true));
    }
}
