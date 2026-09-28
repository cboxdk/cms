<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\AbstractCommand;

use Cbox\Cms\Contracts\Attributes\Command;

/**
 * #[Command] on an abstract class, which no caller can send.
 */
#[Command('fixture.base.run', version: 1)]
abstract readonly class BaseCommand {}
