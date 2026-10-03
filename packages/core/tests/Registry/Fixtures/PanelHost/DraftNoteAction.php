<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\PanelHost;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\NoAggregates;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\PlansNothing;

/**
 * The write action of notes.draft.
 *
 * @implements WriteAction<DraftNote, NoAggregates>
 */
#[Action(handles: DraftNote::class, surfaces: [Surface::Inertia])]
final readonly class DraftNoteAction implements WriteAction
{
    use PlansNothing;
}
