<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\UnknownActionCommand;

use Cbox\Cms\Contracts\Attributes\Command;
use Cbox\Cms\Contracts\Pipeline\Command as CommandInput;

/**
 * A registered command, which a query action beside it names by mistake.
 */
#[Command('fixture.note.rename', version: 1)]
final readonly class RenameNote implements CommandInput {}
