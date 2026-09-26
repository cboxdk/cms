<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\Valid;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Surface;

/**
 * The fixture action for the registry tests. The surfaces are listed out of order on purpose: the
 * registry keeps them in the order of the Surface cases.
 */
#[Action(surfaces: [Surface::Cli, Surface::Rest])]
final readonly class CreateNoteAction
{
    public function handle(CreateNote $command): string
    {
        return $command->title;
    }
}
