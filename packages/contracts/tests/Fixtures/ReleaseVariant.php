<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Fixtures;

use Cbox\Cms\Contracts\Attributes\Command;

/**
 * A command DTO for the attribute tests.
 */
#[Command('entry.release', version: 2)]
final readonly class ReleaseVariant {}
