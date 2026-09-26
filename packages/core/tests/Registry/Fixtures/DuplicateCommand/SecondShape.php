<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\DuplicateCommand;

use Cbox\Cms\Contracts\Attributes\Command;

/**
 * Declares x.y version 1, as FirstShape does: cms:build must refuse both.
 */
#[Command('x.y', version: 1)]
final readonly class SecondShape {}
