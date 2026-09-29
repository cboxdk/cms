<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\UnknownSurface;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\NoAggregates;
use Cbox\Cms\Core\Tests\Registry\FixtureSupport\PlansNothing;

/**
 * A write action that lists a surface as a string, which is not a case of Surface.
 *
 * @implements WriteAction<ShareNote, NoAggregates>
 */
#[Action(handles: ShareNote::class, surfaces: [Surface::Rest, 'graphql'])]
final readonly class ShareNoteAction implements WriteAction
{
    use PlansNothing;
}
