<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\UnknownSurface;

use Cbox\Cms\Contracts\Attributes\Command;
use Cbox\Cms\Contracts\Pipeline\Command as CommandInput;

/**
 * The command the action beside it handles.
 */
#[Command('fixture.note.share', version: 1)]
final readonly class ShareNote implements CommandInput {}
