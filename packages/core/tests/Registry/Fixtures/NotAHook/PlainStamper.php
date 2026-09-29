<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\NotAHook;

use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Phase;

/**
 * A hook of the authorize phase that implements no hook interface.
 */
#[Hook(command: StampNote::class, phase: Phase::Authorize, priority: 0, budgetMs: 1)]
final readonly class PlainStamper {}
