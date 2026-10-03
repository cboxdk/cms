<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\PanelHost;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\FindsNothing;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\NothingFound;

/**
 * The query action of notes.search.
 *
 * @implements QueryAction<SearchNotes, NothingFound>
 */
#[Action(handles: SearchNotes::class)]
final readonly class SearchNotesAction implements QueryAction
{
    use FindsNothing;
}
