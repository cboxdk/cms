<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\Valid;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\NoAggregates;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\PlansNothing;

/**
 * The fixture write action for the registry tests, listing its surfaces out of the enum's order.
 *
 * @implements WriteAction<CreateNote, NoAggregates>
 */
#[Action(handles: CreateNote::class, surfaces: [Surface::Mcp, Surface::Rest])]
final readonly class CreateNoteAction implements WriteAction
{
    use PlansNothing;
}
