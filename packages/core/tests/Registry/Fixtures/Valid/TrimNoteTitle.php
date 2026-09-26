<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\Valid;

use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Phase;

/**
 * The fixture hook for the registry tests.
 */
#[Hook(command: CreateNote::class, phase: Phase::Transform, priority: 10, budgetMs: 5)]
final readonly class TrimNoteTitle
{
    public function transform(string $title): string
    {
        return trim($title);
    }
}
