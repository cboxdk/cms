<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Fixtures;

use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Phase;

/**
 * A transform hook for the attribute tests.
 */
#[Hook(command: ReleaseVariant::class, phase: Phase::Transform, priority: 10, budgetMs: 20)]
final readonly class SlugHook {}
