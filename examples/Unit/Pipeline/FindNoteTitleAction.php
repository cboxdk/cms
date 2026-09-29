<?php

declare(strict_types=1);

namespace Examples\Unit\Pipeline;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Override;

/**
 * The query action behind the note title, exposed on REST and in the panel. It states what a query
 * costs before anything is read, and reads and returns the typed result; the query pipeline has
 * already set the actor's context, authorized the read and checked the cost against the budget.
 *
 * @implements QueryAction<FindNoteTitle, NoteTitle>
 */
#[Action(handles: FindNoteTitle::class, surfaces: [Surface::Inertia, Surface::Rest])]
final readonly class FindNoteTitleAction implements QueryAction
{
    public function __construct(
        private NoteTitles $titles,
    ) {}

    /**
     * One note by its id.
     */
    #[Override]
    public function cost(Query $query): QueryCost
    {
        return new QueryCost(1);
    }

    /**
     * @param  FindNoteTitle  $query
     */
    #[Override]
    public function handle(Query $query): NoteTitle
    {
        return new NoteTitle($this->titles->of($query->note));
    }
}
