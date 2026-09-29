<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\DuplicateAction;

use Cbox\Cms\Contracts\Attributes\Command;
use Cbox\Cms\Contracts\Pipeline\Command as CommandInput;

/**
 * The command that both actions beside it handle.
 */
#[Command('fixture.note.pin', version: 1)]
final readonly class PinNote implements CommandInput {}
