<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\NotAHook;

use Cbox\Cms\Contracts\Attributes\Command;
use Cbox\Cms\Contracts\Pipeline\Command as CommandInput;

/**
 * The command the hooks beside it name.
 */
#[Command('fixture.note.stamp', version: 1)]
final readonly class StampNote implements CommandInput {}
