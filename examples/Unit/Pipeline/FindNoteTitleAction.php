<?php

declare(strict_types=1);

namespace Examples\Unit\Pipeline;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Override;

/**
 * The query action behind the note title, exposed on REST and in the panel. It reads and returns
 * the typed result; the query pipeline has already set the actor's context and authorized it.
 *
 * @implements QueryAction<FindNoteTitle, NoteTitle>
 */
#[Action(surfaces: [Surface::Inertia, Surface::Rest])]
final readonly class FindNoteTitleAction implements QueryAction
{
    public function __construct(
        private NoteTitles $titles,
    ) {}

    /**
     * @param  FindNoteTitle  $query
     */
    #[Override]
    public function handle(Query $query): NoteTitle
    {
        return new NoteTitle($this->titles->of($query->note));
    }
}
