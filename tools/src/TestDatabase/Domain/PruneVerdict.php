<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\TestDatabase\Domain;

/**
 * What `composer test-db:prune` does with one test database: drop it or keep it.
 */
enum PruneVerdict: string
{
    case Drop = 'drop';
    case Keep = 'keep';
}
