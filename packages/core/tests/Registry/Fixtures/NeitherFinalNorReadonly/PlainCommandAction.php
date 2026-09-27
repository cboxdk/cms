<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\NeitherFinalNorReadonly;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Command;
use Cbox\Cms\Contracts\Attributes\Surface;

/**
 * #[Command] and #[Action] on one class that is neither final nor readonly. The build reports the
 * class once, not once per attribute.
 */
#[Command('fixture.plain.run', version: 1)]
#[Action(surfaces: [Surface::Cli])]
class PlainCommandAction
{
    public string $title = '';
}
