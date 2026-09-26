<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\InvalidAttribute;

use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Phase;

/**
 * A hook whose command class does not exist, so the attribute cannot be built.
 */
#[Hook(command: 'Cbox\Cms\Core\Tests\Registry\Fixtures\InvalidAttribute\NoSuchCommand', phase: Phase::Authorize, priority: 0, budgetMs: 1)]
final readonly class HookOnMissingClass {}
