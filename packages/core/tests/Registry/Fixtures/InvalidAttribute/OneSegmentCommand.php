<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\InvalidAttribute;

use Cbox\Cms\Contracts\Attributes\Command;

/**
 * A command whose name has one segment, which the attribute refuses.
 */
#[Command('publish', version: 1)]
final readonly class OneSegmentCommand {}
