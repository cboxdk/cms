<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\NotAnAction;

use Cbox\Cms\Contracts\Attributes\Command;
use Cbox\Cms\Contracts\Pipeline\Command as CommandInput;
use Cbox\Cms\Contracts\Pipeline\Query;

/**
 * The command the actions beside it name.
 */
#[Command('fixture.note.archive', version: 1)]
final readonly class ArchiveNote implements CommandInput, Query {}
